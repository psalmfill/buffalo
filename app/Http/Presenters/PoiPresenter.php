<?php

namespace App\Http\Presenters;

use App\Enums\EyeColour;
use App\Enums\HairColour;
use App\Enums\MaritalStatus;
use App\Enums\PoiStatus;
use App\Enums\RiskLevel;
use App\Enums\TribalMarks;
use App\Models\Person;
use App\Models\PersonOfInterest;
use App\Models\Religion;
use App\Models\State;
use App\Models\ThreatType;
use Carbon\CarbonImmutable;
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
     * The personal details of Phase 11G.4. `sensitive` (religion, political
     * affiliations) is for the person's page only: PDFs and exports leave it
     * out (withoutSensitive()).
     *
     * @return array<string, mixed>
     */
    public static function personalDetails(PersonOfInterest $poi): array
    {
        $poi->loadMissing(['originState', 'originLga', 'person']);
        $religion = $poi->religion === null ? null : Religion::query()->where('code', $poi->religion)->first();
        $option = fn (?\BackedEnum $value) => $value === null ? null : ['value' => $value->value, 'label' => method_exists($value, 'label') ? $value->label() : (string) $value->value];

        return [
            'age' => self::age($poi->person),
            'height_cm' => $poi->height_cm,
            'height_label' => $poi->height_cm === null ? null : self::heightLabel($poi->height_cm),
            'marital_status' => $option($poi->marital_status),
            'origin_state' => $poi->originState === null ? null : ['ulid' => $poi->originState->ulid, 'name' => $poi->originState->name],
            'origin_lga' => $poi->originLga === null ? null : ['ulid' => $poi->originLga->ulid, 'name' => $poi->originLga->name],
            'hometown' => $poi->hometown,
            'hair_colour' => $option($poi->hair_colour),
            'eye_colour' => $option($poi->eye_colour),
            'has_tribal_marks' => $option($poi->has_tribal_marks),
            'tribal_marks_description' => $poi->tribal_marks_description,
            'sensitive' => [
                'religion' => $poi->religion === null ? null : ['value' => $poi->religion, 'label' => $religion->name ?? $poi->religion],
                'political_affiliations' => $poi->political_affiliations,
            ],
        ];
    }

    /**
     * A detail without the special categories (religion, political
     * affiliations): for every PDF and export.
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    public static function withoutSensitive(array $detail): array
    {
        unset($detail['sensitive']);

        return $detail;
    }

    /** Age from the date of birth, else the approximate age (no column of its own). */
    public static function age(?Person $person): ?int
    {
        if ($person === null) {
            return null;
        }

        if (is_string($person->date_of_birth) && $person->date_of_birth !== '') {
            return (int) CarbonImmutable::parse($person->date_of_birth)->diffInYears(CarbonImmutable::today());
        }

        return $person->approximate_age;
    }

    /** "178 cm (5 ft 10 in)". */
    public static function heightLabel(int $cm): string
    {
        $inches = (int) round($cm / 2.54);

        return sprintf('%d cm (%d ft %d in)', $cm, intdiv($inches, 12), $inches % 12);
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
            'criminal_record' => $poi->criminal_record,
            'modus_operandi' => $poi->modus_operandi,
            ...self::personalDetails($poi),
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
            // Personal details (Phase 11G.4).
            'maritalStatuses' => MaritalStatus::options(),
            'hairColours' => HairColour::options(),
            'eyeColours' => EyeColour::options(),
            'tribalMarks' => TribalMarks::options(),
            'religions' => Religion::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['code', 'name'])
                ->map(fn (Religion $r) => ['value' => $r->code, 'label' => $r->name])->values()->all(),
            'states' => State::query()->orderBy('name')->get(['ulid', 'name'])
                ->map(fn (State $st) => ['value' => $st->ulid, 'label' => $st->name])->values()->all(),
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
