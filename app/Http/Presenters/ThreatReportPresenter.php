<?php

namespace App\Http\Presenters;

use App\Enums\Classification;
use App\Enums\ThreatCredibility;
use App\Enums\ThreatGroupRole;
use App\Enums\ThreatIncidentRelation;
use App\Enums\ThreatSourceType;
use App\Enums\ThreatStatus;
use App\Enums\ThreatUrgency;
use App\Models\ThreatReport;
use App\Models\ThreatType;
use App\Support\Jurisdiction\OfficerNames;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

/**
 * Threat reports for the frontend (Phase 6B): ULIDs only, never numeric ids.
 */
final class ThreatReportPresenter
{
    /** Named events shown on the Timeline tab. */
    public const TIMELINE_EVENTS = [
        'created', 'credibility_changed', 'urgency_changed', 'status_changed', 'extended', 'reactivated', 'expired',
        'flashpoint_linked', 'flashpoint_unlinked', 'criminal_group_linked', 'criminal_group_unlinked',
        'incident_linked', 'incident_unlinked', 'incident_link_changed', 'criminal_group_link_changed', 'area_changed',
    ];

    /**
     * @return array{value: string, label: string}
     */
    public static function option(ThreatCredibility|ThreatUrgency|ThreatSourceType|ThreatStatus|ThreatGroupRole|ThreatIncidentRelation|Classification $case): array
    {
        return ['value' => $case->value, 'label' => $case->label()];
    }

    /**
     * A list row or map point; needs withCoordinates() and threatType, lga
     * and state loaded.
     *
     * @return array<string, mixed>
     */
    public static function row(ThreatReport $threat): array
    {
        $soon = CarbonImmutable::now()->addHours((int) config('buffalo.threats.expiring_soon_hours'));

        return [
            'ulid' => $threat->ulid,
            'reference_no' => $threat->reference_no,
            'title' => $threat->title,
            'threat_type' => ['ulid' => $threat->threatType->ulid, 'name' => $threat->threatType->name],
            'credibility' => self::option($threat->credibility),
            'urgency' => self::option($threat->urgency),
            'status' => self::option($threat->status),
            'classification' => self::option($threat->classificationLevel()),
            'lga' => ['ulid' => $threat->lga->ulid, 'name' => $threat->lga->name],
            'state' => ['ulid' => $threat->state->ulid, 'name' => $threat->state->name],
            'latitude' => $threat->latitude,
            'longitude' => $threat->longitude,
            'radius_meters' => $threat->radius_meters,
            'expected_from' => $threat->expected_from?->toIso8601String(),
            'expected_until' => $threat->expected_until?->toIso8601String(),
            'expires_at' => $threat->expires_at->toIso8601String(),
            'expiring_soon' => $threat->isActive() && $threat->expires_at->lessThanOrEqualTo($soon),
            'created_at' => $threat->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(ThreatReport $threat): array
    {
        return [
            ...self::row($threat),
            'description' => $threat->description,
            'source_type' => self::option($threat->source_type),
            'source_notes' => $threat->source_notes,
            'target_description' => $threat->target_description,
            'community' => $threat->community === null ? null : ['ulid' => $threat->community->ulid, 'name' => $threat->community->name],
            'recommended_action' => $threat->recommended_action,
            'status_note' => $threat->status_note,
            'created_by' => OfficerNames::for([$threat->created_by])->get((int) $threat->created_by),
            // For datetime-local inputs (Africa/Lagos).
            'expected_from_local' => self::local($threat->expected_from),
            'expected_until_local' => self::local($threat->expected_until),
            'expires_at_local' => self::local($threat->expires_at),
        ];
    }

    private static function local(?CarbonImmutable $at): ?string
    {
        return $at?->setTimezone((string) config('app.timezone'))->format('Y-m-d\\TH:i');
    }

    /**
     * The named changes, newest first: what changed, when, by whom, and the
     * officer's comment or note.
     *
     * @return list<array{event: string, description: string, at: string, by: string|null, comment: string|null}>
     */
    public static function timeline(ThreatReport $threat): array
    {
        $entries = Activity::query()
            ->where('subject_type', $threat->getMorphClass())
            ->where('subject_id', $threat->id)
            ->whereIn('event', self::TIMELINE_EVENTS)
            ->latest('id')
            ->limit(200)
            ->get();
        $names = OfficerNames::for($entries->map(fn (Activity $a) => $a->causer_id === null ? null : (int) $a->causer_id));

        return array_values($entries->map(function (Activity $a) use ($names): array {
            $properties = $a->properties?->all() ?? [];
            $comment = $properties['comment'] ?? $properties['note'] ?? null;

            return [
                'event' => (string) $a->event,
                'description' => $a->event === 'created' ? 'Threat report recorded' : (string) $a->description,
                'at' => $a->created_at?->toIso8601String() ?? '',
                'by' => $a->causer_id === null ? null : $names->get((int) $a->causer_id),
                'comment' => is_string($comment) ? $comment : null,
            ];
        })->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function options(?ThreatType $keep = null): array
    {
        $explained = fn (ThreatCredibility|ThreatUrgency $c) => [...self::option($c), 'help' => $c->help()];

        return [
            'credibilities' => array_map($explained, ThreatCredibility::cases()),
            'urgencies' => array_map($explained, ThreatUrgency::cases()),
            'sourceTypes' => array_map(self::option(...), ThreatSourceType::cases()),
            'statuses' => array_map(self::option(...), ThreatStatus::cases()),
            'settableStatuses' => array_map(self::option(...), ThreatStatus::settable()),
            'classifications' => array_map(self::option(...), Classification::cases()),
            'groupRoles' => array_map(self::option(...), ThreatGroupRole::cases()),
            'incidentRelations' => array_map(self::option(...), ThreatIncidentRelation::cases()),
            'threatTypes' => ThreatType::query()
                ->where(fn ($q) => $q->where('is_active', true)->when($keep, fn ($q) => $q->orWhereKey($keep?->id)))
                ->orderBy('sort_order')->orderBy('name')
                ->get(['ulid', 'name'])
                ->map(fn (ThreatType $t) => ['value' => $t->ulid, 'label' => $t->name])
                ->values()
                ->all(),
            'defaultExpiryDays' => (int) config('buffalo.threats.default_expiry_days'),
            'maxExpiryDays' => (int) config('buffalo.threats.max_expiry_days'),
        ];
    }
}
