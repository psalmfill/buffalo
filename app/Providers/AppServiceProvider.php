<?php

namespace App\Providers;

use App\Console\Commands\DisabledCipherSweetGenerateKeyCommand;
use App\Console\Commands\DisabledCleanActivitylogCommand;
use App\Models\User;
use App\Policies\ReferenceDataPolicy;
use App\Support\Audit\AuditAccess;
use App\Support\Auth\PasswordRules;
use App\Support\CommunityInformation\ReferenceUsageChecks;
use App\Support\Flashpoints\ReferenceUsageChecks as FlashpointsReferenceUsageChecks;
use App\Support\Incidents\ReferenceUsageChecks as IncidentsReferenceUsageChecks;
use App\Support\Jurisdiction\AuthenticationUserProvider;
use App\Support\Jurisdiction\ContextMode;
use App\Support\Jurisdiction\Contracts\AccessGrantResolver;
use App\Support\Jurisdiction\GrantAccessResolver;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Jurisdiction\JurisdictionRules;
use App\Support\Jurisdiction\RestrictionType;
use App\Support\Persons\ReferenceUsageChecks as PersonsReferenceUsageChecks;
use App\Support\ReferenceData\Contracts\ReferenceUsage;
use App\Support\ReferenceData\ReferenceTable;
use App\Support\ReferenceData\ReferenceUsageRegistry;
use App\Support\Security\ReferenceUsageChecks as SecurityReferenceUsageChecks;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use ParagonIE\CipherSweet\CipherSweet;
use RuntimeException;
use Spatie\Activitylog\Commands\CleanActivitylogCommand;
use Spatie\LaravelCipherSweet\Commands\GenerateKeyCommand;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Whether a lookup value is in use: each module registers its checks
        // (e.g. App\Support\CommunityInformation\ReferenceUsageChecks).
        $this->app->singleton(ReferenceUsageRegistry::class, function (): ReferenceUsageRegistry {
            $registry = new ReferenceUsageRegistry;
            ReferenceUsageChecks::register($registry);
            PersonsReferenceUsageChecks::register($registry);
            SecurityReferenceUsageChecks::register($registry);
            IncidentsReferenceUsageChecks::register($registry);
            FlashpointsReferenceUsageChecks::register($registry);

            return $registry;
        });
        $this->app->bind(ReferenceUsage::class, ReferenceUsageRegistry::class);

        // Fresh per HTTP request and per queued job. HTTP requests start in
        // `none` (SetJurisdictionContext sets the user); the console and
        // queue workers start in `system`.
        $this->app->scoped(JurisdictionContext::class, fn (Application $app): JurisdictionContext => new JurisdictionContext(
            $app->runningInConsole() ? ContextMode::System : ContextMode::None,
        ));

        // Audit records are never deleted: the package's clean command refuses.
        $this->app->bind(CleanActivitylogCommand::class, DisabledCleanActivitylogCommand::class);

        // The package's key command overwrites an existing key outside
        // production; buffalo:encryption-key only creates one.
        $this->app->bind(GenerateKeyCommand::class, DisabledCipherSweetGenerateKeyCommand::class);

        // Fail clearly, before any encryption, when the key is missing.
        $this->app->beforeResolving(CipherSweet::class, function (): void {
            if (config('ciphersweet.provider') === 'string' && blank(config('ciphersweet.providers.string.key'))) {
                throw new RuntimeException('CIPHERSWEET_KEY is not set. Encrypted person fields cannot be read or written. For a new installation run `php artisan buffalo:encryption-key`; otherwise restore the existing key from its backup (docs/security/encryption-keys.md).');
            }
        });

        // Cross-jurisdiction access grants (Phase 3C): persons only.
        $this->app->bind(AccessGrantResolver::class, GrantAccessResolver::class);
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
