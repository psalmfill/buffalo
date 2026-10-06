<?php

namespace App\Http\Controllers\Reports;

use App\Actions\Reports\RequestReport;
use App\Enums\AccessAction;
use App\Enums\Classification;
use App\Enums\ReportType;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ReportRunPresenter;
use App\Http\Requests\Reports\GenerateReportRequest;
use App\Models\CoverageReport;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\ReportRun;
use App\Models\State;
use App\Models\User;
use App\Services\Analytics\Analytics;
use App\Services\Analytics\AnalyticsArea;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Reports\ReportParameters;
use App\Support\Auth\StepUp;
use App\Support\Exports\ExportControls;
use App\Support\Incidents\IncidentFilters;
use App\Support\Jurisdiction\JurisdictionOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports (Phase 7D): choose a type and its parameters, generate on the
 * queue, follow the run in "My reports" (live status on the user's
 * channel) and download it. Only the requester sees or downloads a run;
 * each download is logged (action `export`, context the report type) after
 * the file's SHA-256 is verified.
 */
class ReportController extends Controller
{
    public function index(Request $request, JurisdictionOptions $options, Analytics $analytics): Response
    {
        $this->authorize('viewAny', ReportRun::class);
        $user = $this->actor($request);
        $types = array_values(array_filter(ReportType::cases(), fn (ReportType $t) => $user->can('generate', [ReportRun::class, $t])));
        $states = $options->states($user);

        return Inertia::render('reports/index', [
            'types' => array_map(fn (ReportType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'formats' => array_map(fn ($f) => $f->value, $t->formats()),
                'schedulable' => $t->schedulable() && $user->can('schedule', [ReportRun::class, $t]),
            ], $types),
            'prefill' => $this->prefill($request, $user, $types, $analytics),
            'runs' => fn () => ReportRun::query()->ownedBy($user)->latest('id')->limit(50)->get()
                ->map(fn (ReportRun $r) => ReportRunPresenter::run($r, $user))->values(),
            'options' => [
                'states' => $states->count() > 1 ? $states->sortBy('name')->map(fn (State $s) => ['value' => $s->ulid, 'label' => $s->name])->values() : [],
                'lgas' => $options->lgaQuery($user)->active()->with('state:id,ulid')->orderBy('name')->get()
                    ->map(fn (Lga $l) => ['value' => $l->ulid, 'label' => $l->name, 'state' => $l->state->ulid])->values(),
            ],
            'limits' => ['confirm_rows' => (int) config('buffalo.reports.confirm_rows'), 'max_rows' => (int) config('buffalo.reports.max_rows'), 'expiry_days' => (int) config('buffalo.reports.expiry_days')],
            'highlight' => is_string($request->query('run')) ? $request->query('run') : null,
            'canSchedule' => $user->can('schedule', [ReportRun::class, ReportType::Sitrep]),
        ]);
    }

    public function store(GenerateReportRequest $request, RequestReport $requestReport): RedirectResponse
    {
        $type = $request->reportType();
        $this->authorize('generate', [ReportRun::class, $type]);

        $run = $requestReport($this->actor($request), $type, $request->reportFormat(), $request->reportParameters(), stepUpConfirmed: StepUp::isRecent($request));

        return redirect()->route('reports.index', ['run' => $run->ulid])
            ->with('toast', ['type' => 'success', 'message' => $type->label().' requested. It will appear in My reports when ready.']);
    }

    /** Rows an incident list export would contain (before generating). */
    public function estimate(Request $request, ReportParameters $parameters): JsonResponse
    {
        $this->authorize('generate', [ReportRun::class, ReportType::IncidentList]);
        $user = $this->actor($request);
        $values = $parameters->validate(ReportType::IncidentList, (array) $request->input('parameters', []), $user);
        $rows = RequestReport::estimate($user, $values);

        return response()->json([
            'rows' => $rows,
            // Above the threshold, generating asks for a TOTP code (step-up).
            'step_up' => $rows > (int) config('buffalo.reports.confirm_rows'),
            'over_limit' => $rows > (int) config('buffalo.reports.max_rows'),
        ]);
    }

    public function download(Request $request, ReportRun $run): StreamedResponse
    {
        $this->authorize('download', $run);
        abort_unless($run->isDownloadable() && $run->file_path !== null, 410, 'This report has expired or is not ready.');

        // Secret reports need a recent TOTP confirmation (Phase 8B.2).
        if ($run->classification === Classification::Secret) {
            StepUp::ensure($request);
        }

        $disk = Storage::disk('reports');
        $path = $run->file_path;
        abort_unless($disk->exists($path), 410, 'This report file is no longer available.');

        // Integrity: the file must be exactly what generation stored.
        $stream = $disk->readStream($path);
        abort_if($stream === null, 410);
        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        if (! hash_equals((string) $run->sha256, hash_final($context))) {
            Log::critical('Report file failed its integrity check', ['run' => $run->ulid]);
            abort(409, 'This report file failed its integrity check and cannot be downloaded. Generate it again.');
        }

        $run->recordAccess(AccessAction::Export, $run->report_type->value);
        app(ExportControls::class)->afterExport($this->actor($request));

        return $disk->download($path, $run->fileName(), [
            'Content-Type' => $run->format->mime(),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Pre-filled choices from an "Export" button: ?type=… plus that type's
     * parameters (current incident filters, a record ULID, analytics tab
     * and filters). Records are resolved through the viewer's scopes.
     *
     * @param  list<ReportType>  $types
     * @return array<string, mixed>|null
     */
    private function prefill(Request $request, User $user, array $types, Analytics $analytics): ?array
    {
        $type = ReportType::tryFrom((string) $request->query('type'));

        if ($type === null || ! in_array($type, $types, true)) {
            return null;
        }

        $query = $request->query();

        return match ($type) {
            ReportType::IncidentList => ['type' => $type->value, 'parameters' => ['filters' => IncidentFilters::from((array) ($query['filters'] ?? []))->values]],
            ReportType::Incident => $this->record($type, 'incident', Incident::query()->where('ulid', (string) ($query['incident'] ?? ''))->first(), $user,
                fn (Incident $i) => ($i->reference_no ?? 'Draft').' — '.$i->title),
            ReportType::Coverage => $this->record($type, 'report', CoverageReport::query()->with('lga')->where('ulid', (string) ($query['report'] ?? ''))->first(), $user,
                fn (CoverageReport $r) => $r->lga->name.' LGA · '.$r->periodLabel()),
            ReportType::LgaProfile => ['type' => $type->value, 'parameters' => ['lga' => is_string($query['lga'] ?? null) ? $query['lga'] : null]],
            ReportType::Analytics => $this->analytics($type, $query, $user, $analytics),
            default => ['type' => $type->value, 'parameters' => array_filter(['state' => $query['state'] ?? null, 'lga' => $query['lga'] ?? null], 'is_string')],
        };
    }

    /**
     * @template T of Incident|CoverageReport
     *
     * @param  T|null  $record
     * @param  \Closure(T): string  $label
     * @return array<string, mixed>
     */
    private function record(ReportType $type, string $key, Incident|CoverageReport|null $record, User $user, \Closure $label): array
    {
        if ($record === null || ! $user->can('view', $record)) {
            return ['type' => $type->value, 'parameters' => [], 'record' => null];
        }

        return ['type' => $type->value, 'parameters' => [$key => $record->ulid], 'record' => ['ulid' => $record->ulid, 'label' => $label($record)]];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function analytics(ReportType $type, array $query, User $user, Analytics $analytics): array
    {
        $area = $analytics->area($user, (string) ($query['tab'] ?? '')) ?? ($analytics->areas($user)[0] ?? null);
        $filters = AnalyticsFilters::from((array) ($query['filters'] ?? []), $user);

        return [
            'type' => $type->value,
            'parameters' => ['tab' => $area?->key(), 'filters' => $filters->values()],
            'record' => $area === null ? null : ['ulid' => $area->key(), 'label' => $area->label().' · '.$filters->periodLabel()],
            'tabs' => array_map(fn (AnalyticsArea $a) => ['value' => $a->key(), 'label' => $a->label()], $analytics->areas($user)),
        ];
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
