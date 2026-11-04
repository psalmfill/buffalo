<?php

namespace App\Support\Dashboard;

use App\Enums\CoverageRating;
use App\Enums\CoverageReportStatus;
use App\Models\CoverageReport;
use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use App\Support\Security\CoverageAnalysis;
use App\Support\Security\CoverageSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Security report figures for the dashboards (Phase 4A), always read
 * through the jurisdiction and classification scopes: a report the viewer
 * cannot see counts as missing for them. Since Phase 11G a report submitted
 * during the period counts whatever its period type (CoverageSchedule).
 */
final class CoverageFigures
{
    public function __construct(
        private readonly CoverageSchedule $schedule,
        private readonly CoverageAnalysis $analysis,
    ) {}

    /**
     * LGA chief: the current period's report, the last rating and the
     * uncovered communities of the latest report.
     *
     * @return array<string, mixed>|null
     */
    public function forLga(User $user, Lga $lga): ?array
    {
        if (! $user->can('viewAny', CoverageReport::class)) {
            return null;
        }

        $type = $this->schedule->periodType();
        [$start, $end] = $this->schedule->currentPeriod();
        $current = $this->pick($this->schedule->relevantTo($this->reports()->where('lga_id', $lga->id), $start, $end)->get());
        $latest = $this->reports()->where('lga_id', $lga->id)
            ->whereIn('status', [CoverageReportStatus::Submitted->value, CoverageReportStatus::Reviewed->value])
            ->orderByDesc('period_start')->orderByDesc('submitted_at')->first();
        $basis = $current ?? $latest;

        return [
            'period' => $type->describe($start, $end),
            'due' => $this->schedule->dueDate($end)->toDateString(),
            'current' => $current === null ? null : ['ulid' => $current->ulid, 'status' => ['value' => $current->status->value, 'label' => $current->status->label()]],
            'lastRating' => $latest?->overall_rating === null ? null : [
                'value' => $latest->overall_rating->value,
                'label' => $latest->overall_rating->label(),
                'period' => $latest->periodLabel(),
            ],
            'uncovered' => $basis === null ? null : ['count' => $this->analysis->uncovered($basis)->count(), 'period' => $basis->periodLabel()],
            'canCreate' => $user->can('createFor', [CoverageReport::class, $lga]),
        ];
    }

    /**
     * State roles: every active LGA of the state for the current period,
     * with its report's status and rating, and the reports awaiting review.
     *
     * @param  Collection<int, Lga>  $lgas
     * @return array<string, mixed>|null
     */
    public function forState(User $user, State $state, Collection $lgas): ?array
    {
        if (! $user->can('viewAny', CoverageReport::class)) {
            return null;
        }

        $type = $this->schedule->periodType();
        [$start, $end] = $this->schedule->currentPeriod();
        $reports = $this->schedule->relevantTo($this->reports()->where('state_id', $state->id), $start, $end)->get()
            ->groupBy('lga_id')->map(fn (Collection $rows) => $this->pick($rows));
        $awaiting = $this->reports()->with('lga')->where('state_id', $state->id)
            ->where('status', CoverageReportStatus::Submitted->value)
            ->orderBy('submitted_at')->get();

        return [
            'period' => $type->describe($start, $end),
            'due' => $this->schedule->dueDate($end)->toDateString(),
            'lgas' => $lgas->map(function (Lga $lga) use ($reports): array {
                /** @var CoverageReport|null $report */
                $report = $reports->get($lga->id);

                return [
                    'lga' => ['ulid' => $lga->ulid, 'name' => $lga->name],
                    'report' => $report?->ulid,
                    'status' => $report === null ? ['value' => 'missing', 'label' => 'Missing'] : ['value' => $report->status->value, 'label' => $report->status->label()],
                    'rating' => $report?->overall_rating === null ? null : ['value' => $report->overall_rating->value, 'label' => $report->overall_rating->label()],
                ];
            })->values()->all(),
            'awaitingReview' => $awaiting->filter(fn (CoverageReport $r) => $user->can('review', $r))->map(fn (CoverageReport $r) => [
                'ulid' => $r->ulid,
                'lga' => $r->lga->name,
                'period' => $r->periodLabel(),
                'submitted_at' => $r->submitted_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * National: submission compliance by state for the last completed
     * period, and LGAs rated inadequate or critical, grouped by state.
     *
     * @param  Collection<int, State>  $states
     * @return array<string, mixed>|null
     */
    public function national(User $user, Collection $states): ?array
    {
        if (! $user->can('viewAny', CoverageReport::class)) {
            return null;
        }

        $type = $this->schedule->periodType();
        [$start, $end] = $this->schedule->lastCompletedPeriod();
        // One report per LGA: the latest that counts for the period.
        $submitted = $this->schedule->countingFor($this->reports()->with('lga')->whereIn('state_id', $states->pluck('id')->all()), $start, $end)
            ->get()->groupBy('lga_id')->map(fn (Collection $rows) => $this->pick($rows))->filter()->values();
        $lgaCounts = Lga::query()->active()->whereIn('state_id', $states->pluck('id')->all())
            ->selectRaw('state_id, count(*) AS total')->groupBy('state_id')->toBase()->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->state_id => (int) $row->total]);

        return [
            'period' => $type->describe($start, $end),
            // States with LGAs only (a state without LGAs has nothing to report).
            'compliance' => $states->filter(fn (State $state) => ($lgaCounts[$state->id] ?? 0) > 0)->map(function (State $state) use ($submitted, $lgaCounts): array {
                $total = (int) ($lgaCounts[$state->id] ?? 0);
                $done = $submitted->where('state_id', $state->id)->count();

                return [
                    'state' => ['ulid' => $state->ulid, 'name' => $state->name],
                    'lgas' => $total,
                    'submitted' => $done,
                    'percent' => $total === 0 ? 0 : (int) round($done * 100 / $total),
                ];
            })->values()->all(),
            'poorlyCovered' => $states->map(fn (State $state) => [
                'state' => ['ulid' => $state->ulid, 'name' => $state->name],
                'lgas' => $submitted->where('state_id', $state->id)
                    ->filter(fn (CoverageReport $r) => in_array($r->overall_rating, [CoverageRating::Inadequate, CoverageRating::Critical], true))
                    ->sortByDesc(fn (CoverageReport $r) => $r->overall_rating?->rank())
                    ->map(fn (CoverageReport $r) => [
                        'report' => $r->ulid,
                        'lga' => $r->lga->name,
                        'rating' => ['value' => $r->overall_rating?->value, 'label' => $r->overall_rating?->label()],
                    ])->values()->all(),
            ])->filter(fn (array $row) => $row['lgas'] !== [])->values()->all(),
        ];
    }

    /**
     * The report to show for an LGA and period: a submitted or reviewed one
     * first, then the latest.
     *
     * @param  Collection<int, CoverageReport>  $reports
     */
    private function pick(Collection $reports): ?CoverageReport
    {
        return $reports->sortByDesc(fn (CoverageReport $r) => [$r->status->countsAsSubmitted() ? 1 : 0, $r->submitted_at?->getTimestamp() ?? 0, $r->id])->first();
    }

    /**
     * @return Builder<CoverageReport>
     */
    private function reports(): Builder
    {
        return CoverageReport::query();
    }
}
