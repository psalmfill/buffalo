<?php

namespace App\Support\Flashpoints;

use App\Enums\IncidentStatus;
use App\Enums\SecurityNoteStatus;
use App\Http\Presenters\CommunityInformationPresenter;
use App\Http\Presenters\FlashpointPresenter;
use App\Http\Presenters\IncidentRows;
use App\Models\CommunitySecurityNote;
use App\Models\Flashpoint;
use App\Models\FlashpointIncident;
use App\Models\FlashpointSecurityNote;
use App\Models\Incident;
use App\Models\User;
use App\Support\Jurisdiction\OfficerNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Links and suggestions for a flashpoint, and flashpoints for an incident
 * (Phase 6A). Every query runs through the viewer's scopes: a link appears
 * only when both sides are visible, and no count includes hidden records.
 */
final class FlashpointLinks
{
    /** Suggestions shown at most. */
    private const SUGGESTION_LIMIT = 50;

    /**
     * Linked incidents, newest first, with who linked them and why.
     *
     * @return list<array<string, mixed>>
     */
    public static function incidents(Flashpoint $flashpoint): array
    {
        $links = FlashpointIncident::query()
            ->where('flashpoint_incidents.flashpoint_id', $flashpoint->id)
            ->with(['incident.type', 'incident.lga', 'incident.state'])
            ->get()
            ->sortByDesc(fn (FlashpointIncident $l) => $l->incident->occurred_at)
            ->values();
        $rows = collect(IncidentRows::present($links->map(fn (FlashpointIncident $l) => $l->incident)))->keyBy('ulid');
        $names = OfficerNames::for($links->pluck('linked_by'));

        return array_values($links->map(fn (FlashpointIncident $l) => [
            ...$rows->get($l->incident->ulid, []),
            'link' => self::link($l->ulid, $l->link_note, $l->linked_at, $names->get((int) $l->linked_by)),
        ])->all());
    }

    /**
     * Incidents the viewer can see that are not drafts or invalid, inside the
     * area, within the last $months months, not yet linked; optionally
     * only those whose type is in the threat type's category.
     *
     * @return list<array<string, mixed>>
     */
    public static function incidentSuggestions(Flashpoint $flashpoint, bool $sameCategory, int $months): array
    {
        $since = CarbonImmutable::now()->subMonths($months);

        $incidents = Incident::query()
            ->whereNotIn('incidents.status', [IncidentStatus::Draft->value, IncidentStatus::Invalid->value])
            ->whereNotNull('incidents.location')
            ->whereRaw('ST_DWithin(incidents.location, (SELECT location FROM flashpoints WHERE id = ?), ?)', [$flashpoint->id, $flashpoint->radius_meters])
            ->where('incidents.occurred_at', '>=', $since)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('flashpoint_incidents')
                ->whereColumn('flashpoint_incidents.incident_id', 'incidents.id')
                ->where('flashpoint_incidents.flashpoint_id', $flashpoint->id)
                ->whereNull('flashpoint_incidents.deleted_at'))
            ->when($sameCategory, fn (Builder $q) => $q->whereExists(fn ($t) => $t->select(DB::raw(1))->from('incident_types')
                ->whereColumn('incident_types.id', 'incidents.incident_type_id')
                ->where('incident_types.category', $flashpoint->threatType->category->value)))
            ->with(['type', 'lga', 'state'])
            ->orderByDesc('incidents.occurred_at')
            ->limit(self::SUGGESTION_LIMIT)
            ->get();

        return IncidentRows::present($incidents);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function securityNotes(Flashpoint $flashpoint): array
    {
        $links = FlashpointSecurityNote::query()
            ->where('flashpoint_security_notes.flashpoint_id', $flashpoint->id)
            ->with(['securityNote.community'])
            ->orderByDesc('linked_at')
            ->get();
        $names = OfficerNames::for($links->pluck('linked_by'));

        return array_values($links->map(fn (FlashpointSecurityNote $l) => [
            ...CommunityInformationPresenter::note($l->securityNote),
            'link' => self::link($l->ulid, $l->link_note, $l->linked_at, $names->get((int) $l->linked_by)),
        ])->all());
    }

    /**
     * Linked incidents with a location, as map markers.
     *
     * @return list<array{ulid: string, title: string, reference_no: string|null, latitude: float, longitude: float}>
     */
    public static function incidentMarkers(Flashpoint $flashpoint): array
    {
        return array_values(Incident::query()
            ->whereNotNull('incidents.location')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('flashpoint_incidents')
                ->whereColumn('flashpoint_incidents.incident_id', 'incidents.id')
                ->where('flashpoint_incidents.flashpoint_id', $flashpoint->id)
                ->whereNull('flashpoint_incidents.deleted_at'))
            ->select(['incidents.ulid', 'incidents.title', 'incidents.reference_no'])
            ->selectRaw('ST_Y(incidents.location::geometry) AS latitude, ST_X(incidents.location::geometry) AS longitude')
            ->get()
            ->map(fn (Incident $i) => [
                'ulid' => $i->ulid,
                'title' => $i->title,
                'reference_no' => $i->reference_no,
                'latitude' => (float) $i->getAttribute('latitude'),
                'longitude' => (float) $i->getAttribute('longitude'),
            ])
            ->all());
    }

    /**
     * Unresolved notes the viewer can see that are not yet linked: about
     * the flashpoint's own community, a community inside the area, or
     * (the link picker) any community of its LGA, nearest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function securityNoteSuggestions(Flashpoint $flashpoint): array
    {
        return array_values(CommunitySecurityNote::query()
            ->where('community_security_notes.status', '!=', SecurityNoteStatus::Resolved->value)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('communities')
                ->whereColumn('communities.id', 'community_security_notes.community_id')
                ->where(fn ($c) => $c
                    ->where('communities.lga_id', $flashpoint->lga_id)
                    ->orWhere('communities.id', $flashpoint->community_id)
                    ->orWhereRaw('ST_DWithin(communities.location, (SELECT location FROM flashpoints WHERE id = ?), ?)', [$flashpoint->id, $flashpoint->radius_meters])))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('flashpoint_security_notes')
                ->whereColumn('flashpoint_security_notes.community_security_note_id', 'community_security_notes.id')
                ->where('flashpoint_security_notes.flashpoint_id', $flashpoint->id)
                ->whereNull('flashpoint_security_notes.deleted_at'))
            ->with('community')
            // Inside the area first, then the rest of the LGA; newest first.
            ->orderByRaw('(SELECT ST_DWithin(c.location, f.location, f.radius_meters) FROM communities c, flashpoints f WHERE c.id = community_security_notes.community_id AND f.id = ?) IS NOT TRUE', [$flashpoint->id])
            ->orderByDesc('observed_at')
            ->limit(self::SUGGESTION_LIMIT)
            ->get()
            ->map(fn (CommunitySecurityNote $n) => CommunityInformationPresenter::note($n))
            ->all());
    }

    /**
     * For the incident page: visible flashpoints linked to it, and visible
     * active or monitoring flashpoints whose area contains it ("Near
     * flashpoint") that are not linked.
     *
     * Near rows say whether the viewer may link the incident from there.
     *
     * @return array{linked: list<array<string, mixed>>, near: list<array<string, mixed>>}
     */
    public static function forIncident(Incident $incident, User $viewer): array
    {
        $links = FlashpointIncident::query()
            ->where('flashpoint_incidents.incident_id', $incident->id)
            ->get(['id', 'ulid', 'flashpoint_id', 'link_note', 'linked_at', 'linked_by']);
        $linkedIds = $links->pluck('flashpoint_id')->all();

        $base = fn (): Builder => Flashpoint::query()->withCoordinates()->with(['threatType', 'lga', 'state']);

        $linked = $linkedIds === [] ? collect() : $base()->whereKey($linkedIds)->byConcern()->get();
        $near = $incident->location === null ? collect() : $base()
            ->watched()
            ->whereRaw(Flashpoint::containsSql('flashpoints', '(SELECT location FROM incidents WHERE id = ?)'), [$incident->id, $incident->id])
            ->whereKeyNot($linkedIds)
            ->byConcern()
            ->get();
        $byFlashpoint = $links->keyBy('flashpoint_id');
        $linkable = ! in_array($incident->status, [IncidentStatus::Draft, IncidentStatus::Invalid], true);

        return [
            'linked' => array_values($linked->map(fn (Flashpoint $f) => [
                ...FlashpointPresenter::row($f),
                'link_note' => $byFlashpoint->get($f->id)?->link_note,
            ])->all()),
            'near' => array_values($near->map(fn (Flashpoint $f) => [
                ...FlashpointPresenter::row($f),
                'can_link' => $linkable && $viewer->can('linkIncident', [$f, $incident]),
            ])->all()),
        ];
    }

    /**
     * @return array{ulid: string, note: string|null, linked_at: string, linked_by: string|null}
     */
    private static function link(string $ulid, ?string $note, CarbonImmutable $at, ?string $by): array
    {
        return ['ulid' => $ulid, 'note' => $note, 'linked_at' => $at->toIso8601String(), 'linked_by' => $by];
    }
}
