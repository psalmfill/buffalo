<?php

namespace App\Http\Controllers\Security;

use App\Actions\Security\DiscardCoverageReport;
use App\Actions\Security\ReturnCoverageReport;
use App\Actions\Security\ReviewCoverageReport;
use App\Actions\Security\SaveCoverageReport;
use App\Actions\Security\SubmitCoverageReport;
use App\Enums\CoveragePeriodType;
use App\Http\Controllers\Controller;
use App\Http\Presenters\AgencyFormationPresenter;
use App\Http\Presenters\CoverageReportPresenter;
use App\Http\Requests\Security\CoverageReportRequest;
use App\Models\Community;
use App\Models\CoverageReport;
use App\Models\CoverageReportDeployment;
use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use App\Support\Dashboard\MapView;
use App\Support\Exports\Lists\Exporters\CoverageReportsExporter;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Lens\PlaceFilter;
use App\Support\Security\CoverageAnalysis;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Security coverage reports (Phase 4A). {report} is bound through the
 * jurisdiction and classification scopes. Saving and submitting share one
 * form (`submit`); return, review and discard are separate actions. Every
 * view is access-logged.
 */
class CoverageReportController extends Controller
{
    public function index(Request $request, JurisdictionOptions $options, CoverageReportsExporter $exporter): Response
    {
        $this->authorize('viewAny', CoverageReport::class);
        $user = $this->actor($request);
        $states = $options->states($user);

        $filters = $exporter->filters($request->query(), $user);
        $state = isset($filters['state']) ? $states->firstWhere('ulid', $filters['state']) : null;
        $lgas = $options->lgas($user, $state);

        // The same query as the export (Phase 11E).
        $reports = $exporter->query($user, $filters)
            ->with(['lga', 'state'])
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('coverage-reports/index', [
            'reports' => $reports->through(fn (CoverageReport $r) => [
                ...CoverageReportPresenter::row($r),
                'can_review' => $user->can('review', $r),
            ]),
            'filters' => [
                'state' => $filters['state'] ?? '',
                'zone' => $filters['zone'] ?? '',
                'lga' => $filters['lga'] ?? '',
                'period_type' => $filters['period_type'] ?? '',
                'period' => $filters['period'] ?? '',
                'status' => $filters['status'] ?? '',
                'rating' => $filters['rating'] ?? '',
            ],
            'options' => CoverageReportPresenter::options(),
            'states' => $states->count() > 1 ? $states->map(fn (State $s) => ['value' => $s->ulid, 'label' => $s->name])->values() : [],
            'zones' => PlaceFilter::options($user),
            'lgas' => $lgas->count() > 1 ? $lgas->map(fn (Lga $l) => ['value' => $l->ulid, 'label' => $l->name])->values() : [],
            'can' => ['create' => $user->can('create', CoverageReport::class)],
        ]);
    }

    public function create(Request $request, JurisdictionOptions $options): Response
    {
        $this->authorize('create', CoverageReport::class);
        $user = $this->actor($request);
        $lgas = $options->lgaQuery($user)->active()->with('state:id,ulid,name')->orderBy('name')->get();
        $ulid = $request->query('lga');
        $lga = is_string($ulid) ? $lgas->firstWhere('ulid', $ulid) : ($lgas->count() === 1 ? $lgas->first() : null);

        return $this->form($user, $lgas, $lga, null);
    }

    public function store(CoverageReportRequest $request, SaveCoverageReport $save, SubmitCoverageReport $submit): RedirectResponse
    {
        /** @var Lga $lga */
        $lga = $request->lga();
        $this->authorize('createFor', [CoverageReport::class, $lga]);
        $user = $this->actor($request);

        $report = $save->create($lga, $request->values(), $user);

        if ($request->submitting()) {
            $this->authorize('submit', $report);
            $submit($report, $user);
        }

        return redirect()->route('coverage-reports.show', $report)
            ->with('toast', ['type' => 'success', 'message' => $request->submitting() ? 'Coverage report submitted.' : 'Draft saved.']);
    }

    public function show(Request $request, CoverageReport $report, CoverageAnalysis $analysis): Response
    {
        $this->authorize('view', $report);
        $user = $this->actor($request);

        $report->recordAccess(context: 'view');
        Inertia::encryptHistory();

        $report->load(['lga', 'state']);
        $deployments = CoverageReportDeployment::query()
            ->with(['agency', 'formation', 'communities'])
            ->where('coverage_report_id', $report->id)
            ->orderBy('id')
            ->get();
        $uncovered = $analysis->uncovered($report);

        return Inertia::render('coverage-reports/show', [
            'report' => CoverageReportPresenter::detail($report),
            'deployments' => $deployments->map(fn (CoverageReportDeployment $d) => CoverageReportPresenter::deployment($d))->values(),
            'totals' => $analysis->totals($report),
            'uncovered' => $uncovered->map(fn (Community $c) => [
                'ulid' => $c->ulid,
                'name' => $c->name,
                'latitude' => $c->getAttribute('latitude') === null ? null : (float) $c->getAttribute('latitude'),
                'longitude' => $c->getAttribute('longitude') === null ? null : (float) $c->getAttribute('longitude'),
            ])->values(),
            'communityCount' => Community::query()->where('lga_id', $report->lga_id)->count(),
            'comparison' => $analysis->comparison($report),
            'timeline' => CoverageReportPresenter::timeline($report),
            'map' => MapView::for($report->lga),
            'can' => [
                'update' => $user->can('update', $report),
                'submit' => $user->can('submit', $report),
                'review' => $user->can('review', $report),
                'delete' => $user->can('delete', $report),
            ],
        ]);
    }

    public function edit(Request $request, CoverageReport $report): Response
    {
        $this->authorize('update', $report);
        $report->load(['lga.state', 'state']);

        return $this->form($this->actor($request), collect(), $report->lga, $report);
    }

    public function update(CoverageReportRequest $request, CoverageReport $report, SaveCoverageReport $save, SubmitCoverageReport $submit): RedirectResponse
    {
        $this->authorize('update', $report);
        $user = $this->actor($request);

        $save->update($report, $request->values(), $user);

        if ($request->submitting()) {
            $this->authorize('submit', $report);
            $submit($report, $user);
        }

        return redirect()->route('coverage-reports.show', $report)
            ->with('toast', ['type' => 'success', 'message' => $request->submitting() ? 'Coverage report submitted.' : 'Changes saved.']);
    }

    public function returnReport(Request $request, CoverageReport $report, ReturnCoverageReport $return): RedirectResponse
    {
        $this->authorize('review', $report);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:5000']], [
            'reason.required' => 'Give the reason for returning the report.',
        ]);

        $return($report, $this->actor($request), (string) $data['reason']);

        return redirect()->route('coverage-reports.show', $report)->with('toast', ['type' => 'success', 'message' => 'Report returned for changes.']);
    }

    public function review(Request $request, CoverageReport $report, ReviewCoverageReport $review): RedirectResponse
    {
        $this->authorize('review', $report);
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:5000']]);

        $review($report, $this->actor($request), isset($data['comment']) ? (string) $data['comment'] : null);

        return redirect()->route('coverage-reports.show', $report)->with('toast', ['type' => 'success', 'message' => 'Report marked reviewed.']);
    }

    public function destroy(Request $request, CoverageReport $report, DiscardCoverageReport $discard): RedirectResponse
    {
        $this->authorize('delete', $report);

        $discard($report, $this->actor($request));

        return redirect()->route('coverage-reports.index')->with('toast', ['type' => 'success', 'message' => 'Draft discarded.']);
    }

    /**
     * @param  Collection<int, Lga>  $lgas
     */
    private function form(User $user, Collection $lgas, ?Lga $lga, ?CoverageReport $report): Response
    {
        $deployments = $report === null ? collect() : CoverageReportDeployment::query()
            ->with(['agency', 'formation', 'communities'])
            ->where('coverage_report_id', $report->id)
            ->orderBy('id')
            ->get();

        return Inertia::render('coverage-reports/form', [
            'report' => $report === null ? null : CoverageReportPresenter::detail($report),
            'deployments' => $deployments->map(fn (CoverageReportDeployment $d) => CoverageReportPresenter::deployment($d))->values(),
            'lga' => $lga === null ? null : ['ulid' => $lga->ulid, 'name' => $lga->name],
            'lgas' => $lgas->map(fn (Lga $l) => ['value' => $l->ulid, 'label' => "{$l->name} ({$l->state->name})"])->values(),
            'communities' => $lga === null ? [] : Community::query()->where('lga_id', $lga->id)->orderBy('name')->get(['ulid', 'name'])
                ->map(fn (Community $c) => ['value' => $c->ulid, 'label' => $c->name])->values(),
            'agencies' => AgencyFormationPresenter::agencyOptions(),
            'lgaState' => $lga === null ? null : $lga->loadMissing('state')->state->ulid,
            // Suggested periods: the current and last completed of each type.
            'periods' => $this->suggestedPeriods(CarbonImmutable::today()),
            'options' => CoverageReportPresenter::options(),
            'can' => ['submit' => $report === null ? true : $user->can('submit', $report)],
        ]);
    }

    /**
     * The last completed and the current period of each regular type.
     *
     * @return array<string, list<array{start: string, end: string, label: string}>>
     */
    private function suggestedPeriods(CarbonImmutable $today): array
    {
        $periods = [];

        foreach ([CoveragePeriodType::Weekly, CoveragePeriodType::Monthly, CoveragePeriodType::Quarterly] as $type) {
            foreach ([$type->lastCompletedPeriod($today), $type->periodContaining($today)] as $period) {
                if ($period !== null) {
                    $periods[$type->value][] = [
                        'start' => $period[0]->toDateString(),
                        'end' => $period[1]->toDateString(),
                        'label' => $type->describe($period[0], $period[1]),
                    ];
                }
            }
        }

        return $periods;
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
