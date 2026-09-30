<?php

namespace App\Http\Requests\Incidents;

use App\Enums\CasualtyGroup;
use App\Enums\CasualtyOutcome;
use App\Enums\Classification;
use App\Enums\IncidentAgencyRole;
use App\Enums\IncidentGroupRole;
use App\Enums\OccurrencePrecision;
use App\Enums\PersonInvolvement;
use App\Enums\Severity;
use App\Models\AgencyFormation;
use App\Models\Community;
use App\Models\CriminalGroup;
use App\Models\Incident;
use App\Models\IncidentAgency;
use App\Models\IncidentCriminalGroup;
use App\Models\IncidentPerson;
use App\Models\IncidentType;
use App\Models\Lga;
use App\Models\OrganizationSite;
use App\Models\Person;
use App\Models\PropertyType;
use App\Models\SecurityAgency;
use App\Models\User;
use App\Rules\ActiveOption;
use App\Support\Classification\ClassificationGate;
use App\Support\DynamicFields\DetailsValidator;
use App\Support\DynamicFields\FieldDefinition;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\LocalGovernment\CommunityRules;
use App\Support\LocalGovernment\Nigeria;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Save an incident draft (Phase 5A): the record and the full lists of its
 * related rows. Rows with a `ulid` update existing rows, rows without one
 * are new, and rows left out are removed. Linked persons need
 * `linkToIncident`; groups, formations and sites must be visible to the
 * user; a formation must belong to its agency. `details` are validated
 * against the incident type's schema. Times are local (Africa/Lagos),
 * `Y-m-d\TH:i`. Authorisation is in the controller.
 */
class IncidentRequest extends FormRequest
{
    public const DATETIME = 'Y-m-d\TH:i';

    /**
     * Validated `details`, cleaned by DetailsValidator (set in after()).
     *
     * @var array<string, mixed>
     */
    private array $details = [];

    /**
     * Refuses before validating: creating needs `create`, editing `update`
     * (the controller also authorises the chosen LGA).
     */
    public function authorize(): bool
    {
        /** @var User $user */
        $user = $this->user();
        $incident = $this->incident();

        return $incident === null ? $user->can('create', Incident::class) : $user->can('update', $incident);
    }

    public function incident(): ?Incident
    {
        $incident = $this->route('incident');

        return $incident instanceof Incident ? $incident : null;
    }

    public function lga(): ?Lga
    {
        $ulid = $this->input('lga');

        if (! is_string($ulid)) {
            return null;
        }

        /** @var User $user */
        $user = $this->user();

        return app(JurisdictionOptions::class)->lgaQuery($user)->active()->where('ulid', $ulid)->first();
    }

    public function type(): ?IncidentType
    {
        $ulid = $this->input('incident_type');

        return is_string($ulid) ? IncidentType::query()->where('ulid', $ulid)->first() : null;
    }

    public function community(): ?Community
    {
        $lga = $this->lga();

        return $lga === null ? null : app(CommunityRules::class)->communityOf($lga, $this->input('community'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->incident();

        return [
            'incident_type' => ['required', 'string', new ActiveOption(IncidentType::class, $current?->type->ulid)],
            'details' => ['nullable', 'array'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:20000'],
            'severity' => ['required', Rule::enum(Severity::class)],
            'occurred_at' => ['required', 'date_format:'.self::DATETIME, function (string $attribute, mixed $value, Closure $fail): void {
                $at = self::time($value);

                if ($at !== null && $at->greaterThan(CarbonImmutable::now()->addMinute())) {
                    $fail('The incident cannot have occurred in the future.');
                }
            }],
            'occurred_at_precision' => ['required', Rule::enum(OccurrencePrecision::class)],
            'lga' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
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
            'location_description' => ['nullable', 'string', 'max:2000'],
            'action_taken' => ['nullable', 'string', 'max:20000'],
            'classification' => ['required', Rule::enum(Classification::class), function (string $attribute, mixed $value, Closure $fail): void {
                $level = Classification::tryFrom((string) $value);
                /** @var User $user */
                $user = $this->user();

                if ($level !== null && ! app(ClassificationGate::class)->canAssign($user, $level)) {
                    $fail('You cannot classify a record above your own clearance.');
                }
            }],

            'casualties' => ['present', 'array', 'max:50'],
            'casualties.*.ulid' => ['nullable', 'string', 'distinct'],
            'casualties.*.outcome' => ['required', Rule::enum(CasualtyOutcome::class)],
            'casualties.*.group' => ['required', Rule::enum(CasualtyGroup::class)],
            'casualties.*.agency' => ['nullable', 'string'],
            'casualties.*.adult_male' => ['required', 'integer', 'min:0', 'max:100000'],
            'casualties.*.adult_female' => ['required', 'integer', 'min:0', 'max:100000'],
            'casualties.*.child_male' => ['required', 'integer', 'min:0', 'max:100000'],
            'casualties.*.child_female' => ['required', 'integer', 'min:0', 'max:100000'],
            'casualties.*.unknown' => ['required', 'integer', 'min:0', 'max:100000'],

            'persons' => ['present', 'array', 'max:200'],
            'persons.*.ulid' => ['nullable', 'string', 'distinct'],
            'persons.*.person' => ['sometimes', 'nullable', 'string'],
            'persons.*.unidentified_description' => ['nullable', 'string', 'max:2000'],
            'persons.*.involvement' => ['required', Rule::enum(PersonInvolvement::class)],
            'persons.*.notes' => ['nullable', 'string', 'max:2000'],

            'groups' => ['present', 'array', 'max:50'],
            'groups.*.ulid' => ['nullable', 'string', 'distinct'],
            // Absent on an existing row: keeps a group the editor cannot see.
            'groups.*.group' => ['required_without:groups.*.ulid', 'string', 'distinct'],
            'groups.*.role' => ['required', Rule::enum(IncidentGroupRole::class)],
            'groups.*.notes' => ['nullable', 'string', 'max:2000'],

            'agencies' => ['present', 'array', 'max:50'],
            'agencies.*.ulid' => ['nullable', 'string', 'distinct'],
            'agencies.*.agency' => ['required', 'string'],
            'agencies.*.formation' => ['sometimes', 'nullable', 'string'],
            'agencies.*.role' => ['required', Rule::enum(IncidentAgencyRole::class)],
            'agencies.*.responded_at' => ['nullable', 'date_format:'.self::DATETIME],
            'agencies.*.notes' => ['nullable', 'string', 'max:2000'],

            'properties' => ['present', 'array', 'max:100'],
            'properties.*.ulid' => ['nullable', 'string', 'distinct'],
            'properties.*.property_type' => ['required', 'string'],
            'properties.*.description' => ['nullable', 'string', 'max:2000'],
            'properties.*.quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'properties.*.estimated_value_naira' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'properties.*.site' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'groups.*.group.distinct' => 'This criminal group is already listed.',
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->checkDetails($validator);
            $this->checkCasualties($validator);
            $this->checkPersons($validator);
            $this->checkGroups($validator);
            $this->checkAgencies($validator);
            $this->checkProperties($validator);
        }];
    }

    /**
     * Validated values for SaveIncident, with ids resolved.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $data = $this->validated();
        /** @var IncidentType $type */
        $type = $this->type();
        $text = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;
        $existing = fn (string $table, mixed $ulid): ?int => is_string($ulid) && $this->incident() !== null
            ? (int) DB::table($table)->where('incident_id', $this->incident()->id)->where('ulid', $ulid)->whereNull('deleted_at')->value('id') ?: null
            : null;
        $id = fn (string $model, mixed $ulid): ?int => is_string($ulid) && $ulid !== '' ? (int) $model::query()->where('ulid', $ulid)->value('id') : null;

        return [
            'incident_type_id' => $type->id,
            'details' => $this->details,
            'details_schema_version' => $type->schema_version,
            'title' => trim((string) $data['title']),
            'description' => trim((string) $data['description']),
            'severity' => (string) $data['severity'],
            'occurred_at' => self::time($data['occurred_at']),
            'occurred_at_precision' => (string) $data['occurred_at_precision'],
            'community_id' => $this->community()?->id,
            'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
            'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
            'location_description' => $text($data['location_description'] ?? null),
            'action_taken' => $text($data['action_taken'] ?? null),
            'classification' => (string) $data['classification'],
            'casualties' => array_map(fn (array $row) => [
                'id' => $existing('incident_casualties', $row['ulid'] ?? null),
                'outcome' => (string) $row['outcome'],
                'group' => (string) $row['group'],
                'security_agency_id' => $row['group'] === CasualtyGroup::SecurityPersonnel->value ? $id(SecurityAgency::class, $row['agency'] ?? null) : null,
                'adult_male' => (int) $row['adult_male'],
                'adult_female' => (int) $row['adult_female'],
                'child_male' => (int) $row['child_male'],
                'child_female' => (int) $row['child_female'],
                'unknown' => (int) $row['unknown'],
            ], array_values($data['casualties'])),
            'persons' => array_map(fn (array $row) => [
                'id' => $existing('incident_persons', $row['ulid'] ?? null),
                // Absent: unchanged (a person the editor cannot see is kept).
                ...(array_key_exists('person', $row) ? ['person_id' => $id(Person::class, $row['person'])] : []),
                'unidentified_description' => $text($row['unidentified_description'] ?? null),
                'involvement' => (string) $row['involvement'],
                'notes' => $text($row['notes'] ?? null),
            ], array_values($data['persons'])),
            'groups' => array_map(fn (array $row) => [
                'id' => $existing('incident_criminal_groups', $row['ulid'] ?? null),
                ...(array_key_exists('group', $row) ? ['criminal_group_id' => $id(CriminalGroup::class, $row['group'])] : []),
                'role' => (string) $row['role'],
                'notes' => $text($row['notes'] ?? null),
            ], array_values($data['groups'])),
            'agencies' => array_map(fn (array $row) => [
                'id' => $existing('incident_agencies', $row['ulid'] ?? null),
                'security_agency_id' => $id(SecurityAgency::class, $row['agency']),
                ...(array_key_exists('formation', $row) ? ['agency_formation_id' => $id(AgencyFormation::class, $row['formation'])] : []),
                'role' => (string) $row['role'],
                'responded_at' => isset($row['responded_at']) ? self::time($row['responded_at']) : null,
                'notes' => $text($row['notes'] ?? null),
            ], array_values($data['agencies'])),
            'properties' => array_map(fn (array $row) => [
                'id' => $existing('incident_properties', $row['ulid'] ?? null),
                'property_type_id' => $id(PropertyType::class, $row['property_type']),
                'description' => $text($row['description'] ?? null),
                'quantity' => isset($row['quantity']) ? (int) $row['quantity'] : null,
                'estimated_value_naira' => isset($row['estimated_value_naira']) ? number_format((float) $row['estimated_value_naira'], 2, '.', '') : null,
                ...(array_key_exists('site', $row) ? ['organization_site_id' => $id(OrganizationSite::class, $row['site'])] : []),
            ], array_values($data['properties'])),
        ];
    }

    /** A local (Africa/Lagos) date-time from the form, or null. */
    public static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat(self::DATETIME, $value, config('app.timezone'))?->startOfMinute();
        } catch (InvalidFormatException) {
            return null;
        }
    }

    private function checkDetails(Validator $validator): void
    {
        $type = $this->type();

        if ($type === null) {
            return;
        }

        $current = $this->incident();
        // Old values stay valid only while the type is unchanged.
        $existing = $current !== null && $current->incident_type_id === $type->id ? $current->details : [];

        try {
            $this->details = app(DetailsValidator::class)->validate(
                FieldDefinition::listFromSchema($type->details_schema),
                $this->input('details'),
                $existing,
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($key, $message);
                }
            }
        }
    }

    private function checkCasualties(Validator $validator): void
    {
        $seen = [];

        foreach ((array) $this->input('casualties') as $index => $row) {
            $isSecurity = ($row['group'] ?? null) === CasualtyGroup::SecurityPersonnel->value;
            $agency = $row['agency'] ?? null;

            if ($isSecurity && ! is_string($agency)) {
                $validator->errors()->add("casualties.{$index}.agency", 'Choose the agency of the security personnel.');
            } elseif (! $isSecurity && is_string($agency) && $agency !== '') {
                $validator->errors()->add("casualties.{$index}.agency", 'An agency is recorded for security personnel only.');
            } elseif ($isSecurity && ! SecurityAgency::query()->where('ulid', $agency)->exists()) {
                $validator->errors()->add("casualties.{$index}.agency", 'Choose an agency.');
            }

            $total = 0;
            foreach (['adult_male', 'adult_female', 'child_male', 'child_female', 'unknown'] as $count) {
                $total += (int) ($row[$count] ?? 0);
            }

            if ($total === 0) {
                $validator->errors()->add("casualties.{$index}.adult_male", 'Enter at least one count above zero.');
            }

            $key = ($row['outcome'] ?? '').'|'.($row['group'] ?? '').'|'.($isSecurity ? $agency : '');

            if (isset($seen[$key])) {
                $validator->errors()->add("casualties.{$index}.outcome", 'This outcome and group are already listed.');
            }

            $seen[$key] = true;
        }
    }

    private function checkPersons(Validator $validator): void
    {
        /** @var User $user */
        $user = $this->user();

        foreach ((array) $this->input('persons') as $index => $row) {
            $existing = isset($row['ulid']) && $this->incident() !== null
                ? IncidentPerson::query()->where('incident_id', $this->incident()->id)->where('ulid', $row['ulid'])->first()
                : null;

            if (isset($row['ulid']) && $existing === null) {
                $validator->errors()->add("persons.{$index}.ulid", 'This person is not part of the incident.');

                continue;
            }

            if (array_key_exists('person', $row)) {
                $ulid = $row['person'];
                $person = is_string($ulid) && $ulid !== '' ? Person::query()->where('ulid', $ulid)->first() : null;

                if (is_string($ulid) && $ulid !== '' && ($person === null || ! $user->can('linkToIncident', $person))) {
                    $validator->errors()->add("persons.{$index}.person", 'Choose a person you may record on this incident.');

                    continue;
                }

                $hasPerson = $person !== null;
            } else {
                $hasPerson = $existing?->person_id !== null;
            }

            $description = trim((string) ($row['unidentified_description'] ?? ''));

            if (! $hasPerson && $description === '') {
                $validator->errors()->add("persons.{$index}.unidentified_description", 'Describe the unidentified person, or choose a person on record.');
            }
        }
    }

    private function checkGroups(Validator $validator): void
    {
        foreach ((array) $this->input('groups') as $index => $row) {
            if (array_key_exists('group', $row)) {
                if (! CriminalGroup::query()->where('ulid', $row['group'])->exists()) {
                    $validator->errors()->add("groups.{$index}.group", 'Choose a criminal group you can view.');
                }
            } elseif ($this->incident() === null || ! IncidentCriminalGroup::query()->where('incident_id', $this->incident()->id)->where('ulid', $row['ulid'] ?? null)->exists()) {
                $validator->errors()->add("groups.{$index}.group", 'Choose a criminal group you can view.');
            }
        }
    }

    private function checkAgencies(Validator $validator): void
    {
        $occurred = self::time($this->input('occurred_at'));

        foreach ((array) $this->input('agencies') as $index => $row) {
            $existing = isset($row['ulid']) && $this->incident() !== null
                ? IncidentAgency::query()->where('incident_id', $this->incident()->id)->where('ulid', $row['ulid'])->first()
                : null;
            $agency = SecurityAgency::query()->where('ulid', $row['agency'] ?? null)->first();

            if ($agency === null || (! $agency->is_active && $existing?->security_agency_id !== $agency->id)) {
                $validator->errors()->add("agencies.{$index}.agency", 'Choose an active agency.');

                continue;
            }

            $formationUlid = $row['formation'] ?? null;

            if (is_string($formationUlid) && $formationUlid !== '') {
                $formation = AgencyFormation::query()->where('ulid', $formationUlid)->first();

                if ($formation === null) {
                    $validator->errors()->add("agencies.{$index}.formation", 'Choose a formation you can view.');
                } elseif ($formation->security_agency_id !== $agency->id) {
                    $validator->errors()->add("agencies.{$index}.formation", "The formation must belong to {$agency->name}.");
                }
            }

            $responded = self::time($row['responded_at'] ?? null);

            if ($responded !== null && $occurred !== null && $responded->lessThan($occurred)) {
                $validator->errors()->add("agencies.{$index}.responded_at", 'The response cannot be before the incident occurred.');
            }
        }
    }

    private function checkProperties(Validator $validator): void
    {
        foreach ((array) $this->input('properties') as $index => $row) {
            $type = PropertyType::query()->where('ulid', $row['property_type'] ?? null)->first();

            if ($type === null) {
                $validator->errors()->add("properties.{$index}.property_type", 'Choose a property type.');
            }

            $site = $row['site'] ?? null;

            if (is_string($site) && $site !== '' && ! OrganizationSite::query()->where('ulid', $site)->exists()) {
                $validator->errors()->add("properties.{$index}.site", 'Choose an organization site you can view.');
            }
        }
    }
}
