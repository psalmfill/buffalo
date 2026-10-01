<?php

namespace App\Support\Threats;

use App\Enums\ThreatStatus;
use App\Http\Presenters\CriminalGroupPresenter;
use App\Http\Presenters\FlashpointPresenter;
use App\Http\Presenters\IncidentRows;
use App\Http\Presenters\ThreatReportPresenter;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\ThreatReport;
use App\Models\ThreatReportCriminalGroup;
use App\Models\ThreatReportFlashpoint;
use App\Models\ThreatReportIncident;
use App\Models\User;
use App\Support\Jurisdiction\DisseminatedLinks;
use Illuminate\Support\Facades\DB;

/**
 * Threat report links for pages (Phase 6B). Every query runs through the
 * viewer's scopes: a link appears only when both sides are visible, and no
 * list or count includes hidden records. For a recipient of a disseminated
 * report, link rows are read through DisseminatedLinks (Phase 7A).
 */
final class ThreatLinks
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function flashpoints(ThreatReport $threat): array
    {
        $links = DisseminatedLinks::of(ThreatReportFlashpoint::query()->where('threat_report_flashpoints.threat_report_id', $threat->id), $threat)->get(['id', 'ulid', 'flashpoint_id']);
        $rows = Flashpoint::query()->withCoordinates()->with(['threatType', 'lga', 'state'])
            ->whereKey($links->pluck('flashpoint_id')->all())->byConcern()->get();
        $byFlashpoint = $links->keyBy('flashpoint_id');

        return array_values($rows->map(fn (Flashpoint $f) => [
            ...FlashpointPresenter::row($f),
            'link' => $byFlashpoint->get($f->id)?->ulid,
        ])->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function groups(ThreatReport $threat): array
    {
        return array_values(DisseminatedLinks::of(ThreatReportCriminalGroup::query()
            ->where('threat_report_criminal_groups.threat_report_id', $threat->id), $threat)
            ->with(['criminalGroup.lga', 'criminalGroup.state'])
            ->orderBy('linked_at')
            ->get()
            ->map(fn (ThreatReportCriminalGroup $l) => [
                ...CriminalGroupPresenter::row($l->criminalGroup),
                'role' => ThreatReportPresenter::option($l->role),
                'link' => $l->ulid,
            ])->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function incidents(ThreatReport $threat): array
    {
        $links = DisseminatedLinks::of(ThreatReportIncident::query()
            ->where('threat_report_incidents.threat_report_id', $threat->id), $threat)
            ->with(['incident.type', 'incident.lga', 'incident.state'])
            ->get()
            ->sortByDesc(fn (ThreatReportIncident $l) => $l->incident->occurred_at)
            ->values();
        $rows = collect(IncidentRows::present($links->map(fn (ThreatReportIncident $l) => $l->incident)))->keyBy('ulid');

        return array_values($links->map(fn (ThreatReportIncident $l) => [
            ...$rows->get($l->incident->ulid, []),
            'relation' => ThreatReportPresenter::option($l->relation),
            'link' => $l->ulid,
        ])->all());
    }

    /**
     * For the incident page: visible threat reports linked to it, and
     * visible ones recorded as possibly related at submission and not yet
     * linked, with whether the viewer may mark them materialised.
     *
     * @return array{linked: list<array<string, mixed>>, possible: list<array<string, mixed>>}
     */
    public static function forIncident(Incident $incident, User $viewer): array
    {
        $links = ThreatReportIncident::query()->where('threat_report_incidents.incident_id', $incident->id)->get(['threat_report_id', 'relation']);
        $linkedIds = $links->pluck('threat_report_id')->all();
        $matchIds = DB::table('threat_report_incident_matches')->where('incident_id', $incident->id)->pluck('threat_report_id')->all();
        $base = fn (array $ids) => ThreatReport::query()->withCoordinates()->with(['threatType', 'lga', 'state'])->whereKey($ids)->byUrgency()->get();
        $relations = $links->keyBy('threat_report_id');

        return [
            'linked' => array_values($base($linkedIds)->map(fn (ThreatReport $t) => [
                ...ThreatReportPresenter::row($t),
                'relation' => ThreatReportPresenter::option($relations->get($t->id)->relation),
            ])->all()),
            'possible' => array_values($base(array_values(array_diff($matchIds, $linkedIds)))->map(fn (ThreatReport $t) => [
                ...ThreatReportPresenter::row($t),
                'can_materialise' => $t->status === ThreatStatus::Active && $viewer->can('linkIncident', [$t, $incident]),
            ])->all()),
        ];
    }

    /**
     * Visible threat reports linked to a flashpoint, most urgent first.
     *
     * @return list<array<string, mixed>>
     */
    public static function forFlashpoint(Flashpoint $flashpoint): array
    {
        $ids = DisseminatedLinks::of(ThreatReportFlashpoint::query()->where('threat_report_flashpoints.flashpoint_id', $flashpoint->id), $flashpoint)->pluck('threat_report_id')->all();

        return array_values(ThreatReport::query()->withCoordinates()->with(['threatType', 'lga', 'state'])
            ->whereKey($ids)->byUrgency()->get()
            ->map(fn (ThreatReport $t) => ThreatReportPresenter::row($t))->all());
    }
}
