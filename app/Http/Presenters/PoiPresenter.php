<?php

namespace App\Http\Presenters;

use App\Enums\PoiStatus;
use App\Enums\RiskLevel;
use App\Models\PersonOfInterest;
use App\Models\ThreatType;
use Spatie\Activitylog\Models\Activity;

/**
 * Persons of interest for the browser: ULIDs and labels, never ids.
 * Offence categories (threat type codes) are shown with their names.
 */
final class PoiPresenter
{
    /** Named events that make up a POI's timeline. */
    public const TIMELINE_EVENTS = ['poi_designated', 'poi_status_changed', 'poi_risk_changed', 'poi_reviewed', 'last_known_location_changed', 'person_of_interest_deleted'];

    /** @var array<string, string>|null */
    private static ?array $threatNames = null;

    /**
     * @return array<string, mixed>
     */
    public static function row(PersonOfInterest $poi): array
    {
        return [
            'ulid' => $poi->ulid,
            'person' => PersonPresenter::row($poi->person),
            'risk_level' => ['value' => $poi->risk_level->value, 'label' => $poi->risk_level->label()],
            'status' => ['value' => $poi->status->value, 'label' => $poi->status->label()],
            'offence_categories' => self::offences($poi->offence_categories),
            'lga' => ['ulid' => $poi->lga->ulid, 'name' => $poi->lga->name],
            'last_sighted_at' => $poi->last_sighted_at?->toIso8601String(),
            'review_due_at' => $poi->review_due_at->toDateString(),
            'review_overdue' => $poi->isReviewOverdue(),
        ];
    }

    /**
     * Full details (the person page's POI tab): access-logged by the caller.
     *
     * @return array<string, mixed>
     */
    public static function detail(PersonOfInterest $poi): array
    {
        return [
            ...self::row($poi),
            'offence_codes' => $poi->offence_categories,
            'known_offences' => $poi->known_offences,
            'modus_operandi' => $poi->modus_operandi,
            'latitude' => $poi->latitude,
            'longitude' => $poi->longitude,
            'last_known_location_description' => $poi->last_known_location_description,
            'last_reviewed_at' => $poi->last_reviewed_at?->toIso8601String(),
            'last_reviewed_by' => $poi->reviewer?->name,
            'created_at' => $poi->created_at?->toIso8601String(),
            'timeline' => Activity::query()
                ->with('causer')
                ->where('subject_type', $poi->getMorphClass())
                ->where('subject_id', $poi->id)
                ->whereIn('event', self::TIMELINE_EVENTS)
                ->latest('id')
                ->get()
                ->map(fn (Activity $activity) => [
                    'event' => $activity->event,
                    'description' => $activity->description,
                    'old' => $activity->properties['old'] ?? null,
                    'new' => $activity->properties['new'] ?? null,
                    'comment' => $activity->properties['comment'] ?? null,
                    'by' => $activity->causer?->getAttribute('name'),
                    'at' => $activity->created_at?->toIso8601String(),
                ])->all(),
        ];
    }

    /**
     * @param  list<string>  $codes
     * @return list<array{value: string, label: string}>
     */
    public static function offences(array $codes): array
    {
        $names = self::threatNames();

        return array_map(fn (string $code) => ['value' => $code, 'label' => $names[$code] ?? $code], $codes);
    }

    /**
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        return [
            'riskLevels' => array_map(fn (RiskLevel $r) => ['value' => $r->value, 'label' => $r->label()], RiskLevel::cases()),
            'statuses' => array_map(fn (PoiStatus $s) => ['value' => $s->value, 'label' => $s->label()], PoiStatus::cases()),
            'offenceCategories' => ThreatType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['code', 'name'])
                ->map(fn (ThreatType $t) => ['value' => $t->code, 'label' => $t->name])->values()->all(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function threatNames(): array
    {
        /** @var array<string, string> */
        return self::$threatNames ??= ThreatType::query()->pluck('name', 'code')->all();
    }
}
