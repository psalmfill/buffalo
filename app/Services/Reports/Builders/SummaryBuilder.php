<?php

namespace App\Services\Reports\Builders;

use App\Enums\CasualtyOutcome;
use App\Enums\Permission;
use App\Enums\ReportType;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\ReportRun;
use App\Models\User;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\CasualtyAnalytics;
use App\Services\Analytics\CoverageAnalytics;
use App\Services\Analytics\IncidentAnalytics;
use App\Services\Analytics\ResponseAnalytics;
use App\Services\Analytics\ThreatAnalytics;
use App\Services\Reports\LevelTracker;
use App\Services\Reports\PdfRenderer;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportOutput;
use App\Support\Dashboard\DashboardKpis;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Weekly and monthly summaries (Phase 7D), built from the 7C analytics
 * services through the requester's scopes:
 * - weekly: the 7 days ending on `end` against the 7 before: KPI tiles,
 *   trend, by type, top places, notable (critical and high) incidents,
 *   threat outcomes, flashpoint concern changes, coverage status;
 * - monthly: the month against the previous month and the same month last
 *   year: the incident, casualty, response and threat analyses (by state
 *   for national users).
 *
 * The run's classification is the highest level among the incidents,
 * threat reports and flashpoints in the period that the figures include.
 */
final class SummaryBuilder implements ReportBuilder
{
    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly IncidentAnalytics $incidents,
        private readonly CasualtyAnalytics $casualties,
        private readonly ResponseAnalytics $response,
        private readonly ThreatAnalytics $threats,
        private readonly CoverageAnalytics $coverage,
        private readonly DashboardKpis $kpis,
    ) {}

    public function build(ReportRun $run, User $user): ReportOutput
    {
        $p = $run->parameters;
        $tz = (string) config('app.timezone');
        $place = array_filter(['state' => $p['state'] ?? null, 'lga' => $p['lga'] ?? null]);

        if ($run->report_type === ReportType::Monthly) {
            $month = CarbonImmutable::createFromFormat('!Y-m', (string) $p['month'], $tz) ?: CarbonImmutable::now($tz)->startOfMonth();
            $range = ['period' => 'custom', 'from' => $month->toDateString(), 'to' => $month->endOfMonth()->toDateString(), ...$place];
            $filters = AnalyticsFilters::from([...$range, 'compare' => 'previous', 'granularity' => 'week'], $user);
            $lastYear = AnalyticsFilters::from([...$range, 'compare' => 'last_year', 'granularity' => 'week'], $user);
            $title = 'Monthly summary — '.$month->format('F Y');
        } else {
            $end = CarbonImmutable::createFromFormat('!Y-m-d', (string) $p['end'], $tz) ?: CarbonImmutable::yesterday($tz);
            $filters = AnalyticsFilters::from(['period' => 'custom', 'from' => $end->subDays(6)->toDateString(), 'to' => $end->toDateString(), 'compare' => 'previous', 'granularity' => 'day', ...$place], $user);
            $lastYear = null;
            $title = 'Weekly summary — '.$end->subDays(6)->format('j M').' to '.$end->format('j M Y');
        }

        $levels = new LevelTracker;
        $data = [
            'subtitle' => Places::from($p)->label($user).' · '.$filters->periodLabel().' compared with '.$filters->periodLabel(comparison: true),
            'kpis' => $this->kpis->forFilters($user, $filters),
            'sections' => [],
        ];

        if ($user->checkPermissionTo(Permission::IncidentsView->value)) {
            $this->incidentLevels($filters->incidents($user), $levels);
            $geography = $this->incidents->byGeography($user, $filters);
            $geography['rows'] = array_slice($geography['rows'], 0, $run->report_type === ReportType::Monthly ? 40 : 10);
            $geography['title'] = ($filters->breakdown($user) === 'state' ? 'Top states' : 'Top LGAs');
            $incidentCharts = [$this->incidents->trend($user, $filters)];

            if ($lastYear !== null) {
                $vsLastYear = $this->incidents->trend($user, $lastYear);
                $vsLastYear['key'] = 'trend_last_year';
                $vsLastYear['title'] = 'Incidents compared with the same month last year';
                $incidentCharts[] = $vsLastYear;
                $incidentCharts[] = $this->incidents->byCategory($user, $filters);
            }

            $incidentCharts[] = $this->incidents->byType($user, $filters);
            $incidentCharts[] = $geography;

            if ($lastYear !== null) {
                $incidentCharts[] = $this->incidents->bySeverity($user, $filters);
            }

            $data['sections'][] = ['title' => 'Incidents', 'charts' => $incidentCharts];

            if ($run->report_type === ReportType::Weekly) {
                $notable = $filters->incidents($user)->whereIn('incidents.severity', ['critical', 'high'])->with(['type', 'lga'])
                    ->orderByRaw("CASE incidents.severity WHEN 'critical' THEN 0 ELSE 1 END")->orderByDesc('incidents.occurred_at')->limit(40)->get();
                $data['notable'] = $notable->map(fn (Incident $i) => [
                    'reference' => $i->reference_no, 'title' => $i->title, 'type' => $i->type->name, 'lga' => $i->lga->name,
                    'severity' => $i->severity->label(), 'status' => $i->status->label(), 'occurred' => $i->occurred_at->setTimezone($tz)->format('j M H:i'),
                ])->all();
            } else {
                $data['sections'][] = ['title' => 'Casualties', 'charts' => [
                    $this->casualties->byOutcome($user, $filters),
                    $this->casualties->topTypes($user, $filters, CasualtyOutcome::Killed),
                    $this->casualties->topTypes($user, $filters, CasualtyOutcome::Abducted),
                ]];
                $data['sections'][] = ['title' => 'Response', 'charts' => [
                    $this->response->summary($user, $filters),
                    $this->response->verificationBySeverity($user, $filters),
                    $this->response->byPlace($user, $filters),
                ]];
            }
        }

        if ($user->checkPermissionTo(Permission::ThreatsView->value)) {
            $levels->seeAll($this->threats->threats($user, $filters)->toBase()->distinct()->pluck('threat_reports.classification'));
            $threatCharts = [$this->threats->outcomes($user, $filters)];
            if ($run->report_type === ReportType::Monthly) {
                $threatCharts[] = $this->threats->materialisation($user, $filters);
            }
            $data['sections'][] = ['title' => 'Threat reports', 'charts' => $threatCharts];
        }

        if ($user->checkPermissionTo(Permission::FlashpointsView->value)) {
            $data['flashpointChanges'] = $this->flashpointChanges($user, $filters, $levels);
        }

        if ($run->report_type === ReportType::Weekly && $user->checkPermissionTo(Permission::CoverageView->value)) {
            $data['sections'][] = ['title' => 'Coverage', 'charts' => [$this->coverage->compliance($user, $filters), $this->coverage->ratings($user, $filters)]];
        }

        return $this->pdf->render('reports.summary', $data, $title, $run, $user, $levels);
    }

    /**
     * @param  Builder<Incident>  $incidents
     */
    private function incidentLevels(Builder $incidents, LevelTracker $levels): void
    {
        $levels->seeAll($incidents->toBase()->distinct()->pluck('incidents.classification'));
    }

    /**
     * Flashpoints whose assessed concern changed in the period: from the
     * level before the period (or new) to the latest in it.
     *
     * @return list<array<string, string>>
     */
    private function flashpointChanges(User $user, AnalyticsFilters $filters, LevelTracker $levels): array
    {
        $flashpoints = $filters->narrow(Flashpoint::query()->ownJurisdiction($user), 'flashpoints')->with('lga')->get()->keyBy('id');

        if ($flashpoints->isEmpty()) {
            return [];
        }

        $ids = $flashpoints->keys()->all();
        $latest = DB::table('flashpoint_assessments')->whereIn('flashpoint_id', $ids)
            ->where('assessed_at', '>=', $filters->start)->where('assessed_at', '<', $filters->end)
            ->orderBy('flashpoint_id')->orderByDesc('assessed_at')->orderByDesc('id')
            ->get(['flashpoint_id', 'concern_level'])->unique('flashpoint_id')->keyBy('flashpoint_id');
        $before = DB::table('flashpoint_assessments')->whereIn('flashpoint_id', $latest->keys()->all())
            ->where('assessed_at', '<', $filters->start)
            ->orderBy('flashpoint_id')->orderByDesc('assessed_at')->orderByDesc('id')
            ->get(['flashpoint_id', 'concern_level'])->unique('flashpoint_id')->keyBy('flashpoint_id');
        $rows = [];

        foreach ($latest as $id => $row) {
            $from = $before->get($id)?->concern_level;
            if ($from === $row->concern_level) {
                continue;
            }
            /** @var Flashpoint $f */
            $f = $flashpoints->get($id);
            $levels->see($f->classificationLevel());
            $rows[] = ['name' => $f->name, 'lga' => $f->lga->name, 'from' => $from === null ? 'New' : ucfirst((string) $from), 'to' => ucfirst((string) $row->concern_level)];
        }

        return $rows;
    }
}
