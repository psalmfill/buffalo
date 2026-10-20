<?php

namespace App\Http\Requests\Arrests;

use App\Enums\ArrestStatus;
use App\Enums\Classification;
use App\Enums\OccurrencePrecision;
use App\Models\AgencyFormation;
use App\Models\Arrest;
use App\Models\ArrestPerson;
use App\Models\Community;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\OffenceType;
use App\Models\Person;
use App\Models\SecurityAgency;
use App\Models\User;
use App\Rules\ActiveOption;
use App\Rules\RichTextRule;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\LocalGovernment\CommunityRules;
use App\Support\LocalGovernment\Nigeria;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Record an arrest or edit one (Phase 11C). Times are local
 * (Africa/Lagos) "Y-m-dTH:i" and never in the future.
 *
 * - The formation must belong to the agency and be visible to the user.
 * - The incident (optional) must be visible to the user and not invalid.
 * - Arrested persons: at least one; each a person the user may record
 *   (`linkToArrest`: own jurisdiction or an active grant) or a described
 *   unidentified person, with at least one offence. Rows with a `ulid`
 *   are existing rows of this arrest; rows left out are removed; a row
 *   without `person` keeps its person (one the editor may not see).
 * - Statuses are set on creation and for newly added persons; later
 *   changes go through ChangeArrestStatus (with a comment).
 *
 * Authorisation is in the controller.
 */
class ArrestRequest extends FormRequest
{
    public const DATETIME = 'Y-m-d\TH:i';

    public function authorize(): bool
    {
        return true;
    }

    public function arrest(): ?Arrest
    {
        $arrest = $this->route('arrest');

        return $arrest instanceof Arrest ? $arrest : null;
    }

    public function lga(): ?Lga
    {
        $current = $this->arrest();
        $ulid = $this->input('lga');

        if ($current !== null && (! is_string($ulid) || $ulid === $current->lga->ulid)) {
            return $current->lga;
        }

        if (! is_string($ulid)) {
            return null;
        }

        /** @var User $user */
        $user = $this->user();

        return app(JurisdictionOptions::class)->lgaQuery($user)->active()->where('ulid', $ulid)->first();
    }

    public function community(): ?Community
    {
        $lga = $this->lga();

        return $lga === null ? null : app(CommunityRules::class)->communityOf($lga, $this->input('community'));
    }

    public function incident(): ?Incident
    {
        $ulid = $this->input('incident');

        return is_string($ulid) && $ulid !== '' ? Incident::query()->where('ulid', $ulid)->first() : null;
    }

    public function formation(): ?AgencyFormation
    {
        $ulid = $this->input('formation');

        return is_string($ulid) && $ulid !== '' ? AgencyFormation::query()->where('ulid', $ulid)->first() : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->arrest() === null;
        $statuses = Rule::enum(ArrestStatus::class);

        return [
            'lga' => [$creating ? 'required' : 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->lga() === null) {
                    $fail('Choose an LGA within your jurisdiction.');
                }
            }],
            'community' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->lga() !== null && $this->community() === null) {
                    $fail('The community must be a community of the chosen LGA.');
                }
            }],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:'.Nigeria::MIN_LATITUDE.','.Nigeria::MAX_LATITUDE],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:'.Nigeria::MIN_LONGITUDE.','.Nigeria::MAX_LONGITUDE, function (string $attribute, mixed $value, Closure $fail): void {
                $latitude = $this->input('latitude');
                $lga = $this->lga();

                if ($lga !== null && is_numeric($value) && is_numeric($latitude)) {
                    $error = app(CommunityRules::class)->locationError($lga, (float) $latitude, (float) $value);

                    if ($error !== null) {
                        $fail($error);
                    }
                }
            }],
            'location_description' => ['nullable', 'string', 'max:500'],
            'arrested_at' => ['required', 'date_format:'.self::DATETIME, function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && self::time($value)->greaterThan(CarbonImmutable::now())) {
                    $fail('The time of arrest cannot be in the future.');
                }
            }],
            'arrested_at_precision' => ['required', Rule::enum(OccurrencePrecision::class)],
            'agency' => ['required', 'string', new ActiveOption(SecurityAgency::class, $this->arrest()?->agency->ulid)],
            'formation' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                $formation = $this->formation();

                if ($formation === null || $formation->agency->ulid !== $this->input('agency')) {
                    $fail('Choose a formation of the arresting agency that you can see.');
                }
            }],
            'incident' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                $incident = $this->incident();
                /** @var User $user */
                $user = $this->user();

                if ($incident === null || ! $user->can('linkIncident', [Arrest::class, $incident])) {
                    $fail('Choose an incident you can see that has not been marked invalid.');
                }
            }],
            'status' => $creating ? ['required', $statuses] : ['prohibited'],
            'notes' => ['nullable', new RichTextRule(10000)],
            'classification' => ['required', Rule::enum(Classification::class), function (string $attribute, mixed $value, Closure $fail): void {
                $level = Classification::tryFrom((string) $value);
                /** @var User $user */
                $user = $this->user();

                if ($level !== null && ! app(ClassificationGate::class)->canAssign($user, $level)) {
                    $fail('You cannot classify a record above your own clearance.');
                }
            }],
            'persons' => ['required', 'array', 'min:1', 'max:50'],
            'persons.*.ulid' => ['nullable', 'string', 'distinct'],
            'persons.*.person' => ['sometimes', 'nullable', 'string'],
            'persons.*.unidentified_description' => ['nullable', 'string', 'max:2000'],
            'persons.*.status' => ['nullable', $statuses],
            'persons.*.notes' => ['nullable', 'string', 'max:2000'],
            'persons.*.offences' => ['required', 'array', 'min:1', 'max:20'],
            'persons.*.offences.*.offence' => ['required', 'string'],
            'persons.*.offences.*.details' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'persons.required' => 'Add at least one arrested person.',
            'persons.min' => 'Add at least one arrested person.',
            'persons.*.offences.required' => 'Add at least one offence for each arrested person.',
            'persons.*.offences.min' => 'Add at least one offence for each arrested person.',
            'status.prohibited' => 'Change the status with a comment from the arrest page.',
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkPersons($validator)];
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $data = $this->validated();
        $text = fn (mixed $value) => is_string($value) && trim($value) !== '' ? trim($value) : null;
        $ulids = [];
        foreach ((array) $data['persons'] as $row) {
            foreach ((array) ($row['offences'] ?? []) as $offence) {
                $ulids[] = (string) ($offence['offence'] ?? '');
            }
        }
        $offences = OffenceType::query()->whereIn('ulid', $ulids)->pluck('id', 'ulid');

        return [
            'arrested_at' => self::time((string) $data['arrested_at']),
            'arrested_at_precision' => (string) $data['arrested_at_precision'],
            'security_agency_id' => SecurityAgency::query()->where('ulid', $data['agency'])->value('id'),
            'agency_formation_id' => $this->formation()?->id,
            'community_id' => $this->community()?->id,
            'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
            'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
            'location_description' => $text($data['location_description'] ?? null),
            'incident_id' => $this->incident()?->id,
            'status' => isset($data['status']) ? (string) $data['status'] : null,
            'notes' => $text($data['notes'] ?? null),
            'classification' => (string) $data['classification'],
            'persons' => array_map(fn (array $row) => [
                'id' => $this->existingRow($row['ulid'] ?? null)?->id,
                // Absent: unchanged (a person the editor cannot see is kept).
                ...(array_key_exists('person', $row) ? ['person_id' => is_string($row['person']) && $row['person'] !== '' ? Person::query()->where('ulid', $row['person'])->value('id') : null] : []),
                'unidentified_description' => $text($row['unidentified_description'] ?? null),
                'status' => isset($row['status']) ? (string) $row['status'] : null,
                'notes' => $text($row['notes'] ?? null),
                'offences' => array_values(array_map(fn (array $o) => [
                    'offence_type_id' => (int) $offences->get($o['offence']),
                    'details' => $text($o['details'] ?? null),
                ], $row['offences'])),
            ], array_values($data['persons'])),
        ];
    }

    private function checkPersons(Validator $validator): void
    {
        /** @var User $user */
        $user = $this->user();
        $seenPersons = [];

        foreach ((array) $this->input('persons') as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $existing = $this->existingRow($row['ulid'] ?? null);

            if (isset($row['ulid']) && $existing === null) {
                $validator->errors()->add("persons.{$index}.ulid", 'This person is not part of the arrest.');

                continue;
            }

            if (array_key_exists('person', $row)) {
                $ulid = $row['person'];
                $person = is_string($ulid) && $ulid !== '' ? Person::query()->where('ulid', $ulid)->first() : null;

                if (is_string($ulid) && $ulid !== '' && ($person === null || ! $user->can('linkToArrest', $person))) {
                    $validator->errors()->add("persons.{$index}.person", 'Choose a person you may record on this arrest.');

                    continue;
                }

                $personId = $person?->id;
            } else {
                $personId = $existing?->person_id;
            }

            if ($personId !== null) {
                if (isset($seenPersons[$personId])) {
                    $validator->errors()->add("persons.{$index}.person", 'This person is already listed on the arrest.');
                }
                $seenPersons[$personId] = true;
            } elseif (trim((string) ($row['unidentified_description'] ?? '')) === '') {
                $validator->errors()->add("persons.{$index}.unidentified_description", 'Describe the unidentified person, or choose a person on record.');
            }

            if ($existing === null && $this->arrest() !== null && ! isset($row['status'])) {
                $validator->errors()->add("persons.{$index}.status", 'Choose the status of the newly added person.');
            }

            // Active offences; a deactivated one the person already holds stays valid.
            $held = $existing === null ? [] : OffenceType::query()
                ->whereIn('id', $existing->offences()->pluck('offence_type_id'))->pluck('ulid')->all();
            $seenOffences = [];
            foreach ((array) ($row['offences'] ?? []) as $o => $offence) {
                $ulid = is_array($offence) ? ($offence['offence'] ?? null) : null;
                $valid = is_string($ulid) && OffenceType::query()->where('ulid', $ulid)
                    ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('ulid', $held))->exists();

                if (! $valid) {
                    $validator->errors()->add("persons.{$index}.offences.{$o}.offence", 'Choose an offence from the list.');
                } elseif (isset($seenOffences[$ulid])) {
                    $validator->errors()->add("persons.{$index}.offences.{$o}.offence", 'This offence is already listed for the person.');
                }
                $seenOffences[(string) $ulid] = true;
            }
        }
    }

    private function existingRow(mixed $ulid): ?ArrestPerson
    {
        $arrest = $this->arrest();

        return $arrest !== null && is_string($ulid) && $ulid !== ''
            ? ArrestPerson::query()->where('arrest_id', $arrest->id)->where('ulid', $ulid)->first()
            : null;
    }

    public static function time(string $value): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(self::DATETIME, $value, (string) config('app.timezone')) ?: CarbonImmutable::now();
    }
}
