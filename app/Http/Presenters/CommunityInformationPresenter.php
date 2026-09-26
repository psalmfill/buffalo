<?php

namespace App\Http\Presenters;

use App\Enums\Classification;
use App\Enums\ReligiousInstitutionType;
use App\Enums\SecurityNoteCategory;
use App\Enums\SecurityNoteStatus;
use App\Enums\VulnerableCategory;
use App\Models\CommunitySecurityNote;
use App\Models\ReligiousInstitution;
use App\Models\VulnerablePopulationStat;
use App\Support\Persons\LinkedPerson;
use BackedEnum;

/**
 * Religious institutions, vulnerable population figures and security notes
 * for the browser: ULIDs and labels, never numeric ids.
 */
final class CommunityInformationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function institution(ReligiousInstitution $institution): array
    {
        return [
            'ulid' => $institution->ulid,
            'name' => $institution->name,
            'type' => self::option($institution->type),
            'religion' => ['ulid' => $institution->religion->ulid, 'name' => $institution->religion->name],
            'denomination' => $institution->denomination === null ? null : ['ulid' => $institution->denomination->ulid, 'name' => $institution->denomination->name],
            'community' => ['ulid' => $institution->community->ulid, 'name' => $institution->community->name],
            'estimated_congregation' => $institution->estimated_congregation,
            'latitude' => $institution->latitude,
            'longitude' => $institution->longitude,
            'notes' => $institution->notes,
            'leader' => LinkedPerson::present($institution->leader_person_id, $institution->leader),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function institutionMarker(ReligiousInstitution $institution): ?array
    {
        if ($institution->latitude === null || $institution->longitude === null) {
            return null;
        }

        return [
            'id' => $institution->ulid,
            'position' => [$institution->latitude, $institution->longitude],
            'title' => $institution->name,
            'popup' => $institution->type->label().' · '.$institution->religion->name.' · '.$institution->community->name,
            'kind' => 'religious',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function figure(VulnerablePopulationStat $figure): array
    {
        return [
            'ulid' => $figure->ulid,
            'category' => self::option($figure->category),
            'community' => $figure->community === null ? null : ['ulid' => $figure->community->ulid, 'name' => $figure->community->name],
            'adult_male' => $figure->adult_male,
            'adult_female' => $figure->adult_female,
            'child_male' => $figure->child_male,
            'child_female' => $figure->child_female,
            'total' => $figure->total(),
            'as_of_date' => $figure->as_of_date->toDateString(),
            'source' => $figure->source,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function note(CommunitySecurityNote $note): array
    {
        return [
            'ulid' => $note->ulid,
            'title' => $note->title,
            'category' => self::option($note->category),
            'status' => self::option($note->status),
            'classification' => self::option($note->classification),
            'community' => ['ulid' => $note->community->ulid, 'name' => $note->community->name],
            'observed_at' => $note->observed_at->toDateString(),
            'resolved_at' => $note->resolved_at?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function noteDetail(CommunitySecurityNote $note): array
    {
        return [
            ...self::note($note),
            'description' => $note->description,
            'lga' => ['ulid' => $note->lga->ulid, 'name' => $note->lga->name],
            'created_by' => $note->creator?->name,
            'created_at' => $note->created_at?->toIso8601String(),
            'updated_at' => $note->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function options(): array
    {
        return [
            'institutionTypes' => self::optionsOf(ReligiousInstitutionType::cases()),
            'vulnerableCategories' => self::optionsOf(VulnerableCategory::cases()),
            'noteCategories' => self::optionsOf(SecurityNoteCategory::cases()),
            'noteStatuses' => self::optionsOf(SecurityNoteStatus::cases()),
            'classifications' => self::optionsOf(Classification::cases()),
        ];
    }

    /**
     * @return array{value: string, label: string}
     */
    private static function option(ReligiousInstitutionType|VulnerableCategory|SecurityNoteCategory|SecurityNoteStatus|Classification $case): array
    {
        return ['value' => $case->value, 'label' => $case->label()];
    }

    /**
     * @param  list<BackedEnum&(ReligiousInstitutionType|VulnerableCategory|SecurityNoteCategory|SecurityNoteStatus|Classification)>  $cases
     * @return list<array{value: string, label: string}>
     */
    private static function optionsOf(array $cases): array
    {
        return array_map(fn ($case): array => self::option($case), $cases);
    }
}
