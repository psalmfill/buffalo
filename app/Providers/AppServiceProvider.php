<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Auth\PasswordRules;
use App\Support\Jurisdiction\AuthenticationUserProvider;
use App\Support\Jurisdiction\ContextMode;
use App\Support\Jurisdiction\Contracts\AccessGrantResolver;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Jurisdiction\NullAccessGrantResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Fresh per HTTP request and per queued job. HTTP requests start in
        // `none` (SetJurisdictionContext sets the user); the console and
        // queue workers start in `system`.
        $this->app->scoped(JurisdictionContext::class, fn (Application $app): JurisdictionContext => new JurisdictionContext(
            $app->runningInConsole() ? ContextMode::System : ContextMode::None,
        ));

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
