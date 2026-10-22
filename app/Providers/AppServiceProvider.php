<?php

namespace App\Providers;

use App\Console\Commands\DisabledCipherSweetGenerateKeyCommand;
use App\Console\Commands\DisabledCleanActivitylogCommand;
use App\Console\DemoCommands\BrowserCheckFixturesCommand;
use App\Console\DemoCommands\GenerateDemoBoundaries;
use App\Console\DemoCommands\SeedDemoIncidents;
use App\Console\DemoCommands\SeedUatCommand;
use App\Enums\Permission;
use App\Models\RecordDissemination;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Policies\DisseminationPolicy;
use App\Policies\ReferenceDataPolicy;
use App\Policies\ReportPolicy;
use App\Services\Search\GlobalSearch;
use App\Services\Search\Providers;
use App\Support\Arrests\ReferenceUsageChecks as ArrestsReferenceUsageChecks;
use App\Support\Audit\AuditAccess;
use App\Support\Auth\PasswordRules;
use App\Support\Backups\BackupChecks;
use App\Support\Backups\BackupStatus;
use App\Support\CommunityInformation\ReferenceUsageChecks;
use App\Support\Database\MigrationConnection;
use App\Support\Flashpoints\ReferenceUsageChecks as FlashpointsReferenceUsageChecks;
use App\Support\GoLive\DemoData;
use App\Support\Health\HealthStatus;
use App\Support\Health\SchedulerCheck;
use App\Support\Health\StackChecks;
use App\Support\Help\ManualLibrary;
use App\Support\Incidents\ReferenceUsageChecks as IncidentsReferenceUsageChecks;
use App\Support\Jurisdiction\AuthenticationUserProvider;
use App\Support\Jurisdiction\CombinedAccessResolver;
use App\Support\Jurisdiction\ContextMode;
use App\Support\Jurisdiction\Contracts\AccessGrantResolver;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Jurisdiction\JurisdictionRules;
use App\Support\Jurisdiction\RestrictionType;
use App\Support\Lens\StateLens;
use App\Support\Malware\ClamdScanner;
use App\Support\Malware\MalwareScanner;
use App\Support\Persons\ReferenceUsageChecks as PersonsReferenceUsageChecks;
use App\Support\ReferenceData\Contracts\ReferenceUsage;
use App\Support\ReferenceData\ReferenceTable;
use App\Support\ReferenceData\ReferenceUsageRegistry;
use App\Support\Security\ReferenceUsageChecks as SecurityReferenceUsageChecks;
use App\Support\Threats\ReferenceUsageChecks as ThreatsReferenceUsageChecks;
use App\Support\Uat\Uat;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        // Malware scanning (Phase 8B.4): clamd on the internal network.
        $this->app->bind(MalwareScanner::class, ClamdScanner::class);

        // Health checks run by buffalo:health:monitor (Phase 8C).
        $this->app->bind(BackupStatus::class, fn (): BackupStatus => BackupStatus::make());
        $this->app->singleton(ManualLibrary::class, fn (): ManualLibrary => ManualLibrary::make());
        $this->app->tag([BackupChecks::class, StackChecks::class, SchedulerCheck::class], 'buffalo.health_checks');

        // Whether a lookup value is in use: each module registers its checks
        // (e.g. App\Support\CommunityInformation\ReferenceUsageChecks).
        $this->app->singleton(ReferenceUsageRegistry::class, function (): ReferenceUsageRegistry {
            $registry = new ReferenceUsageRegistry;
            ReferenceUsageChecks::register($registry);
            PersonsReferenceUsageChecks::register($registry);
            SecurityReferenceUsageChecks::register($registry);
            IncidentsReferenceUsageChecks::register($registry);
            FlashpointsReferenceUsageChecks::register($registry);
            ThreatsReferenceUsageChecks::register($registry);
            ArrestsReferenceUsageChecks::register($registry);

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

        // Cross-jurisdiction access grants (Phase 3C, persons) and
        // dissemination (Phase 7A, threat reports and flashpoints).
        $this->app->bind(AccessGrantResolver::class, CombinedAccessResolver::class);

        // Global search (Phase 7B): one provider per module, in section order.
        $this->app->tag([
            Providers\IncidentProvider::class,
            Providers\ThreatReportProvider::class,
            Providers\ArrestProvider::class,
            Providers\FlashpointProvider::class,
            Providers\PersonProvider::class,
            Providers\PersonOfInterestProvider::class,
            Providers\CriminalGroupProvider::class,
            Providers\CommunityProvider::class,
            Providers\OrganizationProvider::class,
            Providers\FormationProvider::class,
            Providers\PlaceProvider::class,
        ], 'search.providers');
        $this->app->when(GlobalSearch::class)->needs('$providers')->giveTagged('search.providers');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Each scheduled command's last run, for System health and the
        // scheduler check (Phase 8C.4).
        Event::listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $e) => HealthStatus::record(
            'schedule.'.self::scheduledCommand($e->task->command ?? $e->task->description ?? ''), $e->task->exitCode === 0 || $e->task->exitCode === null, null, ['seconds' => round($e->runtime, 1)],
        ));
        Event::listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $e) => HealthStatus::record(
            'schedule.'.self::scheduledCommand($e->task->command ?? $e->task->description ?? ''), false, mb_substr($e->exception->getMessage(), 0, 300),
        ));

        // Synthetic data generators exist only where they may run (Phase
        // 9B.1): never listed in a production deployment.
        if ($this->app->runningInConsole() && $this->app->environment(DemoData::REGISTERED_ENVIRONMENTS)) {
            $this->commands([GenerateDemoBoundaries::class, SeedDemoIncidents::class, SeedUatCommand::class, BrowserCheckFixturesCommand::class]);
        }

        $this->configureDefaults();
        $this->configureAuthorization();
        MigrationConnection::register($this->app->make('events'));
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

        // Dissemination (Phase 7A): the rows themselves, and the abilities on
        // the disseminated records (ThreatReport, Flashpoint).
        Gate::policy(RecordDissemination::class, DisseminationPolicy::class);
        Gate::define('disseminate', [DisseminationPolicy::class, 'disseminate']);
        Gate::define('request', [DisseminationPolicy::class, 'request']);
        Gate::define('decideRequest', [DisseminationPolicy::class, 'decideRequest']);

        // Reports (Phase 7D): runs and schedules, owner only.
        Gate::policy(ReportRun::class, ReportPolicy::class);
        Gate::policy(ReportSchedule::class, ReportPolicy::class);

        // Global search (Phase 7B): intelligence roles only.
        Gate::define('globalSearch', fn (User $user): bool => GlobalSearch::canSearch($user));
        // The state lens (Phase 11A.2): national users only.
        Gate::define('useLens', fn (User $user): bool => app(StateLens::class)->available($user));

        // Analytics (Phase 7C): each area also needs its module's permission.
        Gate::define('viewAnalytics', fn (User $user): bool => $user->checkPermissionTo(Permission::AnalyticsView->value));
        Gate::define('exportAnalytics', fn (User $user): bool => $user->checkPermissionTo(Permission::AnalyticsExport->value));

        Gate::define('viewAudit', fn (User $user): bool => AuditAccess::canViewAudit($user));
        // System health (Phase 8C.4): system administration only.
        // Help (Phase 9B.4): every signed-in user with a role.
        Gate::define('viewHelp', fn (User $user): bool => $user->getRole() !== null);
        // UAT feedback (Phase 9B.5): every tester with a role (UAT only, see routes/uat.php).
        Gate::define('leaveUatFeedback', fn (User $user): bool => $user->getRole() !== null);
        Gate::define('viewSystemHealth', fn (User $user): bool => $user->checkPermissionTo(Permission::SystemSettings->value));
        // Exports review (Phase 8B.3): audit.exports and national reach.
        Gate::define('viewExportReview', fn (User $user): bool => AuditAccess::canViewExportReview($user));
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
        // Global search (Phase 7B): searches per user per minute.
        // Report requests and row estimates (Phase 7D): generation is heavy.
        RateLimiter::for('reports', fn (Request $request) => Limit::perMinute((int) config('buffalo.reports.per_minute'))
            ->by((string) $request->user()?->getAuthIdentifier())
            ->response(fn (Request $request, array $headers) => $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['message' => 'Too many report requests. Wait a minute and try again.'], 429, $headers)
                : back()->with('toast', ['type' => 'error', 'message' => 'Too many report requests. Wait a minute and try again.'])));

        RateLimiter::for('global-search', fn (Request $request) => Limit::perMinute((int) config('buffalo.search.per_minute'))
            ->by((string) $request->user()?->getAuthIdentifier())
            ->response(fn (Request $request, array $headers) => $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['message' => 'Too many searches. Wait a minute and try again.'], 429, $headers)
                : back()->with('toast', ['type' => 'error', 'message' => 'Too many searches. Wait a minute and try again.'])));

        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(Uat::productionLike());

        // Same policy in every environment (App\Support\Auth\PasswordRules).
        Password::defaults(fn (): Password => PasswordRules::password());
    }

    /** "'/usr/local/bin/php' 'artisan' buffalo:x --y" => "buffalo:x" */
    private static function scheduledCommand(string $command): string
    {
        return preg_match("/artisan'?\s+([\w:.-]+)/", $command, $m) === 1 ? $m[1] : mb_substr($command, 0, 100);
    }
}
