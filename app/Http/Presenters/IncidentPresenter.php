<?php

namespace App\Http\Presenters;

use App\Enums\CasualtyGroup;
use App\Enums\CasualtyOutcome;
use App\Enums\Classification;
use App\Enums\IncidentAgencyRole;
use App\Enums\IncidentGroupRole;
use App\Enums\IncidentStatus;
use App\Enums\IncidentUpdateType;
use App\Enums\InvalidReason;
use App\Enums\OccurrencePrecision;
use App\Enums\PersonInvolvement;
use App\Enums\Severity;
use App\Http\Requests\Incidents\IncidentRequest;
use App\Models\Incident;
use App\Models\IncidentAgency;
use App\Models\IncidentCasualty;
use App\Models\IncidentCriminalGroup;
use App\Models\IncidentPerson;
use App\Models\IncidentProperty;
use App\Models\IncidentType;
use App\Models\PropertyType;
use App\Models\User;
use App\Support\Jurisdiction\OfficerNames;
use Carbon\CarbonImmutable;

/**
 * Incidents for the browser (Phase 5A): ULIDs and labels, never ids.
 * Linked persons are named only to viewers who can see them; other viewers
 * see "Person on record (not visible to you)" and the involvement.
 */
final class IncidentPresenter
{
    /**
     * @return array{value: string, label: string}
     */
    public static function option(CasualtyGroup|CasualtyOutcome|Classification|IncidentAgencyRole|IncidentGroupRole|IncidentStatus|IncidentUpdateType|InvalidReason|OccurrencePrecision|PersonInvolvement|Severity $value): array
    {
        return ['value' => $value->value, 'label' => $value->label()];
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(Incident $incident): array
    {
        return [
            'ulid' => $incident->ulid,
            'reference_no' => $incident->reference_no,
            'title' => $incident->title,
            'type' => ['ulid' => $incident->type->ulid, 'name' => $incident->type->name],
            'severity' => self::option($incident->severity),
            'status' => self::option($incident->status),
            'classification' => self::option($incident->classificationLevel()),
            'occurred_at' => $incident->occurred_at->toIso8601String(),
            'occurred_at_precision' => self::option($incident->occurred_at_precision),
            'lga' => ['ulid' => $incident->lga->ulid, 'name' => $incident->lga->name],
            'state' => ['ulid' => $incident->state->ulid, 'name' => $incident->state->name],
            'updated_at' => $incident->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Incident $incident): array
    {
        return [
            ...self::row($incident),
            'type' => self::type($incident->type),
            'details' => (object) $incident->details,
            'details_schema_version' => $incident->details_schema_version,
            'description' => $incident->description,
            'occurred_at_local' => self::local($incident->occurred_at),
            'community' => $incident->community === null ? null : ['ulid' => $incident->community->ulid, 'name' => $incident->community->name],
            'latitude' => $incident->getAttribute('latitude') === null ? null : (float) $incident->getAttribute('latitude'),
            'longitude' => $incident->getAttribute('longitude') === null ? null : (float) $incident->getAttribute('longitude'),
            'location_description' => $incident->location_description,
            'action_taken' => $incident->action_taken,
            'created_at' => $incident->created_at?->toIso8601String(),
            'workflow' => self::workflow($incident),
        ];
    }

    /**
     * Workflow facts (Phase 5C): who did what and when, escalation, and the
     * text of returns, resolutions and invalidations. Officer names only.
     *
     * @return array<string, mixed>
     */
    public static function workflow(Incident $incident): array
    {
        $names = OfficerNames::for([
            $incident->submitted_by, $incident->returned_by, $incident->verified_by, $incident->investigation_started_by,
            $incident->resolved_by, $incident->closed_by, $incident->invalidated_by, $incident->escalated_by,
            $incident->escalation_acknowledged_by,
        ]);
        $step = fn (?int $by, ?CarbonImmutable $at) => $at === null ? null : ['by' => $by === null ? null : $names->get($by), 'at' => $at->toIso8601String()];
        // The original of a duplicate, only if the viewer can see it (scoped).
        $original = $incident->duplicate_of_incident_id === null ? null : Incident::query()->find($incident->duplicate_of_incident_id);

        return [
            'submitted' => $step($incident->submitted_by, $incident->submitted_at),
            'returned' => $step($incident->returned_by, $incident->returned_at),
            'return_reason' => $incident->return_reason,
            'verified' => $step($incident->verified_by, $incident->verified_at),
            'investigation_started' => $step($incident->investigation_started_by, $incident->investigation_started_at),
            'resolved' => $step($incident->resolved_by, $incident->resolved_at),
            'resolution_summary' => $incident->resolution_summary,
            'closed' => $step($incident->closed_by, $incident->closed_at),
            'invalidated' => $step($incident->invalidated_by, $incident->invalidated_at),
            'invalid_reason' => $incident->invalid_reason === null ? null : ['value' => $incident->invalid_reason->value, 'label' => $incident->invalid_reason->label()],
            'invalid_note' => $incident->invalid_note,
            'duplicate_of' => $original === null ? null : ['ulid' => $original->ulid, 'reference_no' => $original->reference_no, 'title' => $original->title],
            'escalation' => $incident->escalated_at === null ? null : [
                'at' => $incident->escalated_at->toIso8601String(),
                'by' => $incident->escalated_by === null ? null : $names->get($incident->escalated_by),
                'automatic' => $incident->escalated_by === null,
                'reason' => $incident->escalation_reason,
                'acknowledged' => $step($incident->escalation_acknowledged_by, $incident->escalation_acknowledged_at),
            ],
            'locked' => $incident->isLocked(),
            'requires_amendment_reason' => $incident->requiresAmendmentReason(),
        ];
    }

    /**
     * A possible duplicate (Phase 5C): reference, title, time and status.
     *
     * @return array<string, mixed>
     */
    public static function duplicate(Incident $incident): array
    {
        return [
            'ulid' => $incident->ulid,
            'reference_no' => $incident->reference_no,
            'title' => $incident->title,
            'type' => $incident->type->name,
            'occurred_at' => $incident->occurred_at->toIso8601String(),
            'status' => self::option($incident->status),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function type(IncidentType $type): array
    {
        return [
            'ulid' => $type->ulid,
            'name' => $type->name,
            'category' => ['value' => $type->category->value, 'label' => $type->category->label()],
            'default_severity' => $type->default_severity->value,
            'fields' => $type->details_schema,
            'schema_version' => $type->schema_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function casualty(IncidentCasualty $row): array
    {
        return [
            'ulid' => $row->ulid,
            'outcome' => self::option($row->outcome),
            'group' => self::option($row->group),
            'agency' => $row->agency === null ? null : AgencyFormationPresenter::agency($row->agency),
            'adult_male' => $row->adult_male,
            'adult_female' => $row->adult_female,
            'child_male' => $row->child_male,
            'child_female' => $row->child_female,
            'unknown' => $row->unknown,
            'total' => $row->total(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function person(IncidentPerson $row, User $viewer): array
    {
        $person = $row->person;
        $visible = $person !== null && $viewer->can('view', $person);

        return [
            'ulid' => $row->ulid,
            'person' => $visible ? ['ulid' => $person->ulid, 'name' => $person->name] : null,
            // A person is linked whom the viewer cannot see: nothing more.
            'person_hidden' => $row->person_id !== null && ! $visible,
            'unidentified_description' => $row->person_id === null ? $row->unidentified_description : null,
            'involvement' => self::option($row->involvement),
            'notes' => $row->notes,
            'can_designate_poi' => $visible && $row->involvement === PersonInvolvement::Suspect && $person->personOfInterest === null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function group(IncidentCriminalGroup $row): array
    {
        $group = $row->group;

        return [
            'ulid' => $row->ulid,
            'group' => $group === null ? null : ['ulid' => $group->ulid, 'name' => $group->name],
            'group_hidden' => $group === null,
            'role' => self::option($row->role),
            'notes' => $row->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function agency(IncidentAgency $row): array
    {
        $formation = $row->formation;

        return [
            'ulid' => $row->ulid,
            'agency' => AgencyFormationPresenter::agency($row->agency),
            'formation' => $formation === null ? null : ['ulid' => $formation->ulid, 'name' => $formation->name],
            'formation_hidden' => $row->agency_formation_id !== null && $formation === null,
            'role' => self::option($row->role),
            'responded_at' => $row->responded_at?->toIso8601String(),
            'responded_at_local' => $row->responded_at === null ? null : self::local($row->responded_at),
            'notes' => $row->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function property(IncidentProperty $row): array
    {
        $site = $row->site;

        return [
            'ulid' => $row->ulid,
            'property_type' => ['ulid' => $row->propertyType->ulid, 'name' => $row->propertyType->name],
            'description' => $row->description,
            'quantity' => $row->quantity,
            'estimated_value_naira' => $row->estimated_value_naira,
            'site' => $site === null ? null : ['ulid' => $site->ulid, 'name' => $site->name],
            'site_hidden' => $row->organization_site_id !== null && $site === null,
        ];
    }

    /**
     * Options for the form.
     *
     * @return array<string, mixed>
     */
    public static function options(?IncidentType $keepType = null): array
    {
        $types = IncidentType::query()
            ->where(fn ($q) => $q->where('is_active', true)->when($keepType, fn ($w) => $w->orWhere('id', $keepType?->id)))
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        return [
            'types' => $types->map(fn (IncidentType $t) => self::type($t))->values(),
            'severities' => array_map(self::option(...), Severity::cases()),
            'precisions' => array_map(self::option(...), OccurrencePrecision::cases()),
            'outcomes' => array_map(self::option(...), CasualtyOutcome::cases()),
            'groups' => array_map(self::option(...), CasualtyGroup::cases()),
            'involvements' => array_map(self::option(...), PersonInvolvement::cases()),
            'groupRoles' => array_map(self::option(...), IncidentGroupRole::cases()),
            'agencyRoles' => array_map(self::option(...), IncidentAgencyRole::cases()),
            'classifications' => array_map(self::option(...), Classification::cases()),
            'agencies' => AgencyFormationPresenter::agencyOptions(),
            'propertyTypes' => PropertyType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (PropertyType $p) => ['value' => $p->ulid, 'label' => $p->name])->values(),
        ];
    }

    /** A date-time for a datetime-local input, in the app time zone. */
    public static function local(CarbonImmutable $at): string
    {
        return $at->setTimezone(config('app.timezone'))->format(IncidentRequest::DATETIME);
    }
}
