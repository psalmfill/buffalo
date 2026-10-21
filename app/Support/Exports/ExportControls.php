<?php

namespace App\Support\Exports;

use App\Enums\AccessAction;
use App\Enums\Classification;
use App\Enums\ReportType;
use App\Enums\Role;
use App\Exceptions\ExportQuotaExceeded;
use App\Models\IncidentEvidence;
use App\Models\ReportRun;
use App\Models\User;
use App\Notifications\ExportVolumeAlert;
use App\Support\Notifications\NotifyRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Export quotas and unusual-volume alerts (Phase 8B.3).
 *
 * Daily quotas per user (Africa/Lagos day), from `buffalo.exports`:
 * - `rows`: rows in list exports requested today (the incident list, and
 *   every list exporter since Phase 11E);
 * - `report_runs`: reports requested today (scheduled runs do not count);
 * - `evidence_downloads`: evidence originals downloaded today;
 * - `sensitive_exports` (Phase 11E): exports of the sensitive lists
 *   requested today, limited separately (buffalo.exports.daily_sensitive_exports).
 * Counts come from report_runs and record_access_logs (the audit trail),
 * so they cannot be reset by the user. Exceeding a quota blocks further
 * exports of that kind until midnight; viewing records is unaffected.
 *
 * Alerts to the DG and the DDGs covering the user (never the user), once per
 * user, rule and day: passing `alert_fraction` of any quota, or exporting
 * secret material (secret report downloads, secret evidence originals)
 * more than `secret_alert_count` times.
 */
final class ExportControls
{
    public const QUOTAS = ['rows', 'report_runs', 'evidence_downloads'];

    public function __construct(private readonly NotifyRole $notify) {}

    /**
     * Today's usage.
     *
     * @return array{rows: int, report_runs: int, evidence_downloads: int, secret_exports: int, sensitive_exports: int}
     */
    public function usage(User $user): array
    {
        $since = self::startOfDay();
        $runs = DB::table('report_runs')->where('requested_by', $user->id)->whereNull('report_schedule_id')->where('created_at', '>=', $since);
        $logs = fn () => DB::table('record_access_logs')->where('record_access_logs.user_id', $user->id)->where('record_access_logs.created_at', '>=', $since);

        return [
            'rows' => (int) (clone $runs)->whereIn('report_type', [ReportType::IncidentList->value, ReportType::ListExport->value])->where('status', '<>', 'failed')->sum(DB::raw('COALESCE(row_count, 0)')),
            'report_runs' => (clone $runs)->count(),
            'evidence_downloads' => $logs()->where('subject_type', (new IncidentEvidence)->getMorphClass())->where('action', AccessAction::Download->value)->count(),
            'secret_exports' => $logs()->whereIn('action', [AccessAction::Export->value, AccessAction::Download->value])
                ->where(fn ($q) => $q
                    ->where(fn ($r) => $r->where('subject_type', (new ReportRun)->getMorphClass())
                        ->whereIn('subject_id', DB::table('report_runs')->where('classification', Classification::Secret->value)->select('id')))
                    ->orWhere(fn ($e) => $e->where('subject_type', (new IncidentEvidence)->getMorphClass())
                        ->whereIn('subject_id', DB::table('incident_evidence')->where('classification', Classification::Secret->value)->select('id'))))
                ->count(),
            // Failed runs count too: the limit is on requests, which alert at once.
            'sensitive_exports' => (clone $runs)->where('is_sensitive', true)->count(),
        ];
    }

    /** @return array{rows: int, report_runs: int, evidence_downloads: int} */
    public static function quotas(): array
    {
        return [
            'rows' => (int) config('buffalo.exports.daily_rows'),
            'report_runs' => (int) config('buffalo.exports.daily_report_runs'),
            'evidence_downloads' => (int) config('buffalo.exports.daily_evidence_downloads'),
        ];
    }

    /**
     * Before a report is requested (by the user, not a schedule).
     *
     * @throws ExportQuotaExceeded
     */
    public function assertCanRequestReport(User $user, ?int $rows = null): void
    {
        $usage = $this->usage($user);
        $quotas = self::quotas();

        if ($usage['report_runs'] >= $quotas['report_runs']) {
            throw new ExportQuotaExceeded("You have reached today's limit of {$quotas['report_runs']} reports. It resets at midnight.");
        }

        if ($rows !== null && $quotas['rows'] < $usage['rows'] + $rows) {
            throw new ExportQuotaExceeded('This export would take you past today\'s limit of '.number_format($quotas['rows']).' exported rows ('.number_format($usage['rows']).' used). It resets at midnight.');
        }
    }

    /**
     * Before a sensitive list is exported (Phase 11E).
     *
     * @throws ExportQuotaExceeded
     */
    public function assertCanExportSensitive(User $user): void
    {
        $limit = (int) config('buffalo.exports.daily_sensitive_exports');

        if ($this->usage($user)['sensitive_exports'] >= $limit) {
            throw new ExportQuotaExceeded("You have reached today's limit of {$limit} sensitive list exports. It resets at midnight.");
        }
    }

    /**
     * Before an evidence original is downloaded.
     *
     * @throws ExportQuotaExceeded
     */
    public function assertCanDownloadEvidence(User $user): void
    {
        $quota = self::quotas()['evidence_downloads'];

        if ($this->usage($user)['evidence_downloads'] >= $quota) {
            throw new ExportQuotaExceeded("You have reached today's limit of {$quota} evidence downloads. It resets at midnight.");
        }
    }

    /**
     * After an export, download or report request: alerts on unusual volume.
     *
     * @return list<string> the rules that alerted now
     */
    public function afterExport(User $user): array
    {
        $usage = $this->usage($user);
        $quotas = self::quotas();
        $fraction = (float) config('buffalo.exports.alert_fraction');
        $alerted = [];

        foreach (self::QUOTAS as $quota) {
            if ($quotas[$quota] > 0 && $usage[$quota] > $quotas[$quota] * $fraction) {
                $alerted[] = "half_{$quota}";
            }
        }

        if ($usage['secret_exports'] > (int) config('buffalo.exports.secret_alert_count')) {
            $alerted[] = 'secret_exports';
        }

        $sent = [];
        foreach ($alerted as $rule) {
            $inserted = DB::table('export_alerts')->insertOrIgnore([
                'user_id' => $user->id, 'rule' => $rule, 'day' => self::startOfDay()->toDateString(), 'created_at' => now(),
            ]);

            if ($inserted > 0) {
                $this->alert($user, $rule, $usage);
                $sent[] = $rule;
            }
        }

        return $sent;
    }

    /**
     * @param  array{rows: int, report_runs: int, evidence_downloads: int, secret_exports: int, sensitive_exports: int}  $usage
     */
    private function alert(User $user, string $rule, array $usage): void
    {
        $notification = new ExportVolumeAlert(
            $user->name,
            $user->getRole()?->label() ?? 'No role',
            $user->jurisdictionLabel(),
            $rule,
            $usage,
            self::quotas(),
        );
        $notMe = fn (User $recipient) => $recipient->id !== $user->id;

        $this->notify->send($notification, Role::DirectorGeneral, only: $notMe);
        $this->notify->send($notification, Role::DeputyDirectorGeneral, $user->lga ?? $user->state, $notMe);
    }

    private static function startOfDay(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('app.timezone'))->startOfDay();
    }
}
