<?php

namespace App\Providers;

use App\Console\Commands\DisabledCleanActivitylogCommand;
use App\Models\User;
use App\Policies\ReferenceDataPolicy;
use App\Support\Audit\AuditAccess;
use App\Support\Auth\PasswordRules;
use App\Support\Jurisdiction\AuthenticationUserProvider;
use App\Support\Jurisdiction\ContextMode;
use App\Support\Jurisdiction\Contracts\AccessGrantResolver;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Jurisdiction\JurisdictionRules;
use App\Support\Jurisdiction\NullAccessGrantResolver;
use App\Support\Jurisdiction\RestrictionType;
use App\Support\ReferenceData\Contracts\ReferenceUsage;
use App\Support\ReferenceData\NoReferenceUsage;
use App\Support\ReferenceData\ReferenceTable;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Commands\CleanActivitylogCommand;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // No module references lookup values yet; later phases bind a real check.
        $this->app->bind(ReferenceUsage::class, NoReferenceUsage::class);

        // Fresh per HTTP request and per queued job. HTTP requests start in
        // `none` (SetJurisdictionContext sets the user); the console and
        // queue workers start in `system`.
        $this->app->scoped(JurisdictionContext::class, fn (Application $app): JurisdictionContext => new JurisdictionContext(
            $app->runningInConsole() ? ContextMode::System : ContextMode::None,
        ));

        // Audit records are never deleted: the package's clean command refuses.
        $this->app->bind(CleanActivitylogCommand::class, DisabledCleanActivitylogCommand::class);

        // Cross-jurisdiction access grants arrive in Phase 3.
        $this->app->bind(AccessGrantResolver::class, NullAccessGrantResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
    }

    /**
     * Authentication user lookups must not be jurisdiction-scoped (see
     * AuthenticationUserProvider). Deactivated, deleted or locked accounts
     * are denied every ability, whatever their role.
     */
    protected function configureAuthorization(): void
    {
        Auth::provider('buffalo', fn (Application $app, array $config): AuthenticationUserProvider => new AuthenticationUserProvider(
            $app->make(Hasher::class),
            $config['model'],
        ));

        Gate::before(fn (User $user): ?bool => $user->isAccessBlocked() ? false : null);

        // One policy for every lookup table (reference data, Phase 2A).
        foreach (ReferenceTable::cases() as $table) {
            Gate::policy($table->modelClass(), ReferenceDataPolicy::class);
        }

        Gate::define('viewAudit', fn (User $user): bool => AuditAccess::canViewAudit($user));
        Gate::define('viewLoginHistory', fn (User $user): bool => AuditAccess::canViewLoginHistory($user));

        // A dashboard needs a role whose jurisdiction data is consistent
        // (fails closed, like the jurisdiction scope).
        Gate::define('viewDashboard', fn (User $user): bool => app(JurisdictionRules::class)
            ->restrictionFor($user, visibleToSuperAdmin: true)->type !== RestrictionType::Nothing);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Same policy in every environment (App\Support\Auth\PasswordRules).
        Password::defaults(fn (): Password => PasswordRules::password());
    }
}
