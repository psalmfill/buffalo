<?php

namespace App\Http\Presenters;

use App\Enums\Classification;
use App\Enums\ConcernLevel;
use App\Enums\FlashpointKind;
use App\Enums\FlashpointStatus;
use App\Models\Flashpoint;
use App\Models\FlashpointAssessment;
use App\Models\ThreatType;
use App\Support\Jurisdiction\OfficerNames;
use Illuminate\Support\Collection;

/**
 * Flashpoints for the frontend (Phase 6A): ULIDs only, never numeric ids.
 */
final class FlashpointPresenter
{
    /**
     * @return array{value: string, label: string}
     */
    public static function option(FlashpointKind|ConcernLevel|FlashpointStatus|Classification $case): array
    {
        return ['value' => $case->value, 'label' => $case->label()];
    }

    /**
     * A list row (or map area); needs withCoordinates() and threatType,
     * lga and state loaded.
     *
     * @return array<string, mixed>
     */
    public static function row(Flashpoint $flashpoint): array
    {
        return [
            'ulid' => $flashpoint->ulid,
            'name' => $flashpoint->name,
            'kind' => self::option($flashpoint->kind),
            'threat_type' => ['ulid' => $flashpoint->threatType->ulid, 'name' => $flashpoint->threatType->name],
            'concern_level' => self::option($flashpoint->concern_level),
            'status' => self::option($flashpoint->status),
            'classification' => self::option($flashpoint->classificationLevel()),
            'lga' => ['ulid' => $flashpoint->lga->ulid, 'name' => $flashpoint->lga->name],
            'state' => ['ulid' => $flashpoint->state->ulid, 'name' => $flashpoint->state->name],
            'latitude' => $flashpoint->latitude,
            'longitude' => $flashpoint->longitude,
            'radius_meters' => $flashpoint->radius_meters,
            'last_assessed_at' => $flashpoint->last_assessed_at->toIso8601String(),
            'review_due_at' => $flashpoint->review_due_at->toDateString(),
            'review_overdue' => $flashpoint->isReviewOverdue(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Flashpoint $flashpoint): array
    {
        $names = OfficerNames::for([$flashpoint->last_assessed_by, $flashpoint->created_by]);

        return [
            ...self::row($flashpoint),
            'description' => $flashpoint->description,
            'recommended_action' => $flashpoint->recommended_action,
            'community' => $flashpoint->community === null ? null : ['ulid' => $flashpoint->community->ulid, 'name' => $flashpoint->community->name],
            'last_assessed_by' => $names->get((int) $flashpoint->last_assessed_by),
            'created_by' => $names->get((int) $flashpoint->created_by),
            'created_at' => $flashpoint->created_at->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, FlashpointAssessment>  $assessments
     * @return list<array<string, mixed>>
     */
    public static function assessments(Collection $assessments): array
    {
        $names = OfficerNames::for($assessments->pluck('assessed_by'));

        return array_values($assessments->map(fn (FlashpointAssessment $a) => [
            'ulid' => $a->ulid,
            'concern_level' => self::option($a->concern_level),
            'status' => self::option($a->status),
            'assessment' => $a->assessment,
            'recommended_action' => $a->recommended_action,
            // Rich text since Phase 11B; older assessments are plain text, shown as before.
            'format' => $a->isHtml() ? 'html' : 'text',
            'assessed_by' => $names->get($a->assessed_by),
            'assessed_at' => $a->assessed_at->toIso8601String(),
        ])->all());
    }

    /**
     * @param  ThreatType|null  $keep  the current (possibly deactivated) threat type
     * @return array<string, mixed>
     */
    public static function options(?ThreatType $keep = null): array
    {
        return [
            'kinds' => array_map(self::option(...), FlashpointKind::cases()),
            'concernLevels' => array_map(self::option(...), ConcernLevel::cases()),
            'statuses' => array_map(self::option(...), FlashpointStatus::cases()),
            'classifications' => array_map(self::option(...), Classification::cases()),
            'threatTypes' => ThreatType::query()
                ->where(fn ($q) => $q->where('is_active', true)->when($keep, fn ($q) => $q->orWhereKey($keep?->id)))
                ->orderBy('sort_order')->orderBy('name')
                ->get(['ulid', 'name', 'category'])
                ->map(fn (ThreatType $t) => ['value' => $t->ulid, 'label' => $t->name])
                ->values()
                ->all(),
            'reviewDays' => array_combine(
                array_map(fn (ConcernLevel $c) => $c->value, ConcernLevel::cases()),
                array_map(fn (ConcernLevel $c) => $c->reviewDays(), ConcernLevel::cases()),
            ),
        ];
    }
}
