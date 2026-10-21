<?php

namespace App\Http\Controllers\Audit;

use App\Enums\AccessAction;
use App\Enums\Classification;
use App\Enums\JurisdictionLevel;
use App\Enums\ReportType;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AnalyticsExport;
use App\Models\Incident;
use App\Models\IncidentEvidence;
use App\Models\ReportRun;
use App\Models\State;
use App\Models\User;
use App\Support\Exports\Lists\ListExporters;
use App\Support\Jurisdiction\JurisdictionOptions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Exports review (Phase 8B.3): who exported, downloaded or printed what,
 * when, at which classification and how many rows, from record_access_logs.
 *
 * Gate `viewExportReview`: `audit.exports` (DG and DDGs; super admins only
 * if explicitly granted it) and system or national reach. DDGs with zones see only
 * officers posted in their zones. Read-only, like every audit viewer.
 */
class ExportReviewController extends Controller
{
    private const ACTIONS = [AccessAction::Export, AccessAction::Download, AccessAction::Print];

    public function index(Request $request, JurisdictionOptions $options): Response
    {
        $this->authorize('viewExportReview');
        /** @var User $viewer */
        $viewer = $request->user();
        $filters = $request->validate([
            'user' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'size:26'],
            'kind' => ['nullable', 'string', 'in:report,evidence,print,analytics'],
            'classification' => ['nullable', 'string', 'in:restricted,confidential,secret'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            // Phase 11E: exports of the sensitive lists only.
            'sensitive' => ['nullable', 'in:1'],
        ]);
        $states = $options->states($viewer);

        $rows = $this->query($viewer, array_values(array_map('intval', $states->pluck('id')->all())), $filters)
            ->orderByDesc('l.created_at')->orderByDesc('l.id')
            ->paginate(50)->withQueryString();

        $tz = (string) config('app.timezone');

        return Inertia::render('audit/exports', [
            'entries' => $rows->through(fn (\stdClass $r): array => [
                'at' => CarbonImmutable::parse((string) $r->created_at, $tz)->toIso8601String(),
                'officer' => $r->officer,
                'role' => $r->role === null ? null : Role::tryFrom((string) $r->role)?->label(),
                'state' => $r->state,
                'action' => AccessAction::from((string) $r->action)->label(),
                'what' => self::what($r),
                'classification' => $r->classification === null ? 'Aggregate figures' : Classification::from((string) $r->classification)->label(),
                'rows' => $r->row_count === null ? null : (int) $r->row_count,
                // Sensitive list exports (Phase 11E) are highlighted, with the stated reason.
                'sensitive' => (bool) $r->is_sensitive,
                'reason' => $r->is_sensitive ? $r->reason : null,
            ]),
            'filters' => array_merge(['user' => '', 'state' => '', 'kind' => '', 'classification' => '', 'from' => '', 'to' => '', 'sensitive' => ''], array_filter($filters)),
            'states' => $states->sortBy('name')->map(fn (State $s) => ['value' => $s->ulid, 'label' => $s->name])->values(),
        ]);
    }

    /**
     * @param  list<int>  $stateIds
     * @param  array<string, mixed>  $filters
     */
    private function query(User $viewer, array $stateIds, array $filters): Builder
    {
        $report = (new ReportRun)->getMorphClass();
        $evidence = (new IncidentEvidence)->getMorphClass();
        $incident = (new Incident)->getMorphClass();
        $analytics = (new AnalyticsExport)->getMorphClass();
        $tz = (string) config('app.timezone');

        return DB::table('record_access_logs as l')
            ->join('users as u', 'u.id', '=', 'l.user_id')
            ->leftJoin('states as s', 's.id', '=', 'u.state_id')
            ->leftJoin('model_has_roles as mr', fn ($j) => $j->on('mr.model_id', '=', 'u.id')->where('mr.model_type', (new User)->getMorphClass()))
            ->leftJoin('roles as r', 'r.id', '=', 'mr.role_id')
            ->leftJoin('report_runs as rr', fn ($j) => $j->on('rr.id', '=', 'l.subject_id')->where('l.subject_type', $report))
            ->leftJoin('incident_evidence as e', fn ($j) => $j->on('e.id', '=', 'l.subject_id')->where('l.subject_type', $evidence))
            ->leftJoin('incidents as i', fn ($j) => $j->on('i.id', '=', 'l.subject_id')->where('l.subject_type', $incident))
            ->leftJoin('incidents as ei', 'ei.id', '=', 'e.incident_id')
            ->whereIn('l.action', array_map(fn (AccessAction $a) => $a->value, self::ACTIONS))
            ->whereIn('l.subject_type', [$report, $evidence, $incident, $analytics])
            // DDGs with zones: officers posted in their zones only.
            ->when($viewer->jurisdiction_level === JurisdictionLevel::National && $viewer->zones()->exists(), fn (Builder $q) => $q->whereIn('u.state_id', $stateIds))
            ->when($filters['user'] ?? null, fn (Builder $q, string $name) => $q->where('u.name', 'ilike', '%'.addcslashes($name, '%_\\').'%'))
            ->when($filters['state'] ?? null, fn (Builder $q, string $state) => $q->where('s.ulid', $state))
            ->when($filters['kind'] ?? null, fn (Builder $q, string $kind) => $q->where('l.subject_type', match ($kind) {
                'report' => $report, 'evidence' => $evidence, 'print' => $incident, default => $analytics,
            }))
            ->when($filters['classification'] ?? null, fn (Builder $q, string $level) => $q->whereRaw('COALESCE(rr.classification, e.classification, i.classification) = ?', [$level]))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('l.created_at', '>=', CarbonImmutable::parse($from, $tz)->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('l.created_at', '<', CarbonImmutable::parse($to, $tz)->addDay()->startOfDay()))
            ->when(isset($filters['sensitive']), fn (Builder $q) => $q->where('rr.is_sensitive', true))
            ->select([
                'l.id', 'l.created_at', 'l.action', 'l.subject_type', 'l.context',
                'u.name as officer', 'r.name as role', 's.name as state',
                'rr.ulid as report_ulid', 'rr.report_type', 'rr.row_count', 'rr.is_sensitive', 'rr.reason', DB::raw("rr.parameters->>'list' AS list_key"),
                'i.reference_no as incident_reference', 'ei.reference_no as evidence_incident_reference',
                DB::raw('COALESCE(rr.classification, e.classification, i.classification) AS classification'),
            ]);
    }

    private static function what(\stdClass $r): string
    {
        return match (true) {
            $r->report_ulid !== null && $r->list_key !== null => 'List export: '.(ListExporters::find((string) $r->list_key)?->label() ?? (string) $r->list_key).' · RPT-'.mb_strtoupper((string) $r->report_ulid),
            $r->report_ulid !== null => (ReportType::tryFrom((string) $r->report_type)?->label() ?? 'Report').' · RPT-'.mb_strtoupper((string) $r->report_ulid),
            $r->evidence_incident_reference !== null => 'Evidence original · '.$r->evidence_incident_reference,
            $r->incident_reference !== null => 'Incident print · '.$r->incident_reference,
            default => 'Analytics chart',
        };
    }
}
