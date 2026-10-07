<?php

namespace App\Providers;

use App\Actions\Auth\AuthenticateUser;
use App\Actions\Fortify\DisableTwoFactorAuthentication;
use App\Actions\Fortify\EnableTwoFactorAuthentication;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Requests\Auth\TwoFactorLoginRequest;
use App\Http\Responses\GenericPasswordResetLinkResponse;
use App\Support\Auth\PasswordRules;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication as FortifyDisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication as FortifyEnableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest as FortifyTwoFactorLoginRequest;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Resolves the 2FA-challenged user through the unscoped auth provider.
        $this->app->bind(FortifyTwoFactorLoginRequest::class, TwoFactorLoginRequest::class);

        // Mandatory 2FA: confirmed 2FA cannot be re-keyed or disabled by users.
        $this->app->bind(FortifyEnableTwoFactorAuthentication::class, EnableTwoFactorAuthentication::class);
        $this->app->bind(FortifyDisableTwoFactorAuthentication::class, DisableTwoFactorAuthentication::class);

        // "Forgot password" gives one generic answer in every case.
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, GenericPasswordResetLinkResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, GenericPasswordResetLinkResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Password step of every login: lockout, inactive accounts, history,
        // one generic error message (App\Actions\Auth\AuthenticateUser).
        Fortify::authenticateUsing(fn (Request $request) => app(AuthenticateUser::class)($request));
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(function (Request $request) {
            // Every arrival at sign-in (after signing out, or when the session
            // expired) discards the encrypted history of the last session (8A).
            Inertia::clearHistory();

            return Inertia::render('auth/login', [
                'canResetPassword' => Features::enabled(Features::resetPasswords()),
                'status' => $request->session()->get('status'),
            ]);
        });

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => PasswordRules::passwordRulesAttribute(),
            'passwordRequirements' => PasswordRules::descriptions(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        // Per link (and IP), plus a per-IP ceiling high enough for a
        // training room behind one address (Phase 9B: onboarding sessions).
        // The 64-character random token makes guessing infeasible either way.
        RateLimiter::for('account-setup', function (Request $request) {
            $token = (string) $request->route('token');

            return [
                Limit::perMinute(10)->by('link:'.$request->ip().'|'.hash('sha256', $token)),
                Limit::perMinute((int) config('buffalo.auth.setup_per_minute_per_ip'))->by('ip:'.$request->ip()),
            ];
        });

        // Per email+IP and per IP, in addition to the per-account lockout.
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());

            return [
                Limit::perMinute(5)->by($throttleKey),
                Limit::perMinute(20)->by('ip:'.$request->ip()),
            ];
        });
    }
}
