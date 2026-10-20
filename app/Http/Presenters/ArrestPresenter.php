<?php

namespace App\Http\Presenters;

use App\Enums\ArrestStatus;
use App\Enums\CasualtyOutcome;
use App\Enums\Classification;
use App\Enums\OccurrencePrecision;
use App\Models\Arrest;
use App\Models\ArrestPerson;
use App\Models\ArrestPersonOffence;
use App\Models\ArrestStatusHistory;
use App\Models\Incident;
use App\Models\OffenceType;
use App\Models\SecurityAgency;
use App\Models\User;
use App\Support\Arrests\ArrestSuggestions;
use App\Support\Jurisdiction\OfficerNames;
use Illuminate\Support\Facades\DB;

/**
 * Arrests for the pages (Phase 11C). Never numeric ids. A person's name is
 * shown only when the viewer can see that person (the person's scopes
 * decide); otherwise "Person on record (not visible to you)".
 */
final class ArrestPresenter
{
    /**
     * @return array{value: string, label: string}
     */
    public static function option(ArrestStatus|OccurrencePrecision|Classification $case): array
    {
        return ['value' => $case->value, 'label' => $case->label()];
    }

    /**
     * A list row; needs agency, lga, incident and persons.offences.offenceType loaded.
     *
     * @return array<string, mixed>
     */
    public static function row(Arrest $arrest): array
    {
        $offences = $arrest->persons->flatMap(fn (ArrestPerson $p) => $p->offences)
            ->map(fn (ArrestPersonOffence $o) => $o->offenceType->name)->unique()->values()->all();

        return [
            'ulid' => $arrest->ulid,
            'reference_no' => $arrest->reference_no,
            'arrested_at' => $arrest->arrested_at->toIso8601String(),
            'arrested_at_precision' => self::option($arrest->arrested_at_precision),
            'agency' => ['ulid' => $arrest->agency->ulid, 'name' => $arrest->agency->name, 'short_name' => $arrest->agency->short_name],
            'persons_count' => $arrest->persons->count(),
            'offences' => $offences,
            'status' => self::option($arrest->status),
            'classification' => self::option($arrest->classificationLevel()),
            'lga' => ['ulid' => $arrest->lga->ulid, 'name' => $arrest->lga->name],
            'incident' => $arrest->incident === null ? null : ['ulid' => $arrest->incident->ulid, 'reference_no' => $arrest->incident->reference_no, 'title' => $arrest->incident->title],
        ];
    }

    /**
     * The arrest itself; needs withCoordinates() and agency, formation,
     * lga, state, community and incident loaded.
     *
     * @return array<string, mixed>
     */
    public static function detail(Arrest $arrest): array
    {
        return [
            'ulid' => $arrest->ulid,
            'reference_no' => $arrest->reference_no,
            'arrested_at' => $arrest->arrested_at->toIso8601String(),
            'arrested_at_precision' => self::option($arrest->arrested_at_precision),
            // For the form's datetime-local field (Africa/Lagos).
            'arrested_at_local' => $arrest->arrested_at->setTimezone((string) config('app.timezone'))->format('Y-m-d\TH:i'),
            'agency' => ['ulid' => $arrest->agency->ulid, 'name' => $arrest->agency->name, 'short_name' => $arrest->agency->short_name],
            'formation' => $arrest->formation === null ? null : ['ulid' => $arrest->formation->ulid, 'name' => $arrest->formation->name],
            'formation_hidden' => $arrest->agency_formation_id !== null && $arrest->formation === null,
            'lga' => ['ulid' => $arrest->lga->ulid, 'name' => $arrest->lga->name],
            'state' => ['ulid' => $arrest->state->ulid, 'name' => $arrest->state->name],
            'community' => $arrest->community === null ? null : ['ulid' => $arrest->community->ulid, 'name' => $arrest->community->name],
            'latitude' => $arrest->latitude,
            'longitude' => $arrest->longitude,
            'location_description' => $arrest->location_description,
            'incident' => $arrest->incident === null ? null : [
                'ulid' => $arrest->incident->ulid,
                'reference_no' => $arrest->incident->reference_no,
                'title' => $arrest->incident->title,
                'status' => ['value' => $arrest->incident->status->value, 'label' => $arrest->incident->status->label()],
            ],
            'incident_hidden' => $arrest->incident_id !== null && $arrest->incident === null,
            'status' => self::option($arrest->status),
            'notes' => $arrest->notes,
            'classification' => self::option($arrest->classificationLevel()),
            'created_at' => $arrest->created_at->toIso8601String(),
        ];
    }

    /**
     * The arrested persons, with offences, statuses and the POI suggestions
     * for the viewer (never applied automatically).
     *
     * @return list<array<string, mixed>>
     */
    public static function persons(Arrest $arrest, User $viewer): array
    {
        $rows = ArrestPerson::query()->where('arrest_id', $arrest->id)
            ->with(['person', 'offences.offenceType'])->orderBy('id')->get();

        return array_values($rows->map(function (ArrestPerson $row) use ($viewer): array {
            $person = $row->person;

            return [
                'ulid' => $row->ulid,
                'person' => $person === null ? null : ['ulid' => $person->ulid, 'name' => $person->name],
                'person_hidden' => $row->person_id !== null && $person === null,
                'unidentified_description' => $row->unidentified_description,
                'status' => self::option($row->status),
                'notes' => $row->notes,
                'offences' => array_values($row->offences->map(fn (ArrestPersonOffence $o) => [
                    'offence' => ['value' => $o->offenceType->ulid, 'label' => $o->offenceType->name],
                    'details' => $o->details,
                ])->all()),
                // Suggestions only: the user acts through the POI pages.
                'suggestion' => $person === null ? null : ArrestSuggestions::for($person, $viewer),
            ];
        })->all());
    }

    /**
     * The incident's reported `arrested` casualty count against the persons
     * on its arrests, both as the viewer may see them.
     *
     * @return array{reported: int, recorded: int, differs: bool}
     */
    public static function comparison(Incident $incident): array
    {
        $reported = (int) DB::table('incident_casualties')
            ->where('incident_id', $incident->id)->whereNull('deleted_at')
            ->where('outcome', CasualtyOutcome::Arrested->value)
            ->sum(DB::raw('adult_male + adult_female + child_male + child_female + unknown'));
        // Scoped: arrests (and arrested persons) the viewer may see.
        $recorded = ArrestPerson::query()->whereHas('arrest', fn ($q) => $q->where('incident_id', $incident->id))->count();

        return ['reported' => $reported, 'recorded' => $recorded, 'differs' => $reported !== $recorded];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function timeline(Arrest $arrest): array
    {
        $rows = ArrestStatusHistory::query()->where('arrest_id', $arrest->id)
            ->with('arrestPerson.person')->orderBy('changed_at')->orderBy('id')->get();
        $names = OfficerNames::for($rows->pluck('changed_by'));

        return array_values($rows->map(fn (ArrestStatusHistory $h) => [
            'at' => $h->changed_at->toIso8601String(),
            'who' => $h->changed_by === null ? null : $names->get($h->changed_by),
            'scope' => $h->arrest_person_id === null ? 'Arrest' : ($h->arrestPerson?->person->name ?? ($h->arrestPerson?->person_id === null ? 'Unidentified person' : 'Person on record (not visible to you)')),
            'from' => $h->from_status === null ? null : self::option($h->from_status),
            'to' => self::option($h->to_status),
            'comment' => $h->comment,
        ])->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function options(?Arrest $arrest = null): array
    {
        $agencies = SecurityAgency::query()->active()->orderBy('name')->get();
        if ($arrest !== null && ! $agencies->contains('id', $arrest->security_agency_id)) {
            $agencies->push($arrest->agency);
        }

        return [
            'statuses' => array_map(self::option(...), ArrestStatus::cases()),
            'precisions' => array_map(self::option(...), OccurrencePrecision::cases()),
            'classifications' => array_map(self::option(...), Classification::cases()),
            'agencies' => $agencies->map(fn (SecurityAgency $a) => ['value' => $a->ulid, 'label' => $a->name])->values()->all(),
            'offences' => OffenceType::query()->active()->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (OffenceType $o) => ['value' => $o->ulid, 'label' => $o->name])->values()->all(),
        ];
    }
}
