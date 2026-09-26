<?php

namespace App\Http\Presenters;

use App\Enums\PoliticalOfficeType;
use App\Models\PoliticalOffice;

/**
 * Political offices for the browser: ULIDs and labels, never ids.
 */
final class PoliticalPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function office(PoliticalOffice $office, bool $withPerson = true): array
    {
        return [
            'ulid' => $office->ulid,
            'office' => ['value' => $office->office->value, 'label' => $office->office->label()],
            'title' => $office->title(),
            'office_title' => $office->office_title,
            'party' => $office->party,
            'state' => ['ulid' => $office->state->ulid, 'name' => $office->state->name],
            'senatorial_district' => $office->senatorialDistrict === null ? null : ['ulid' => $office->senatorialDistrict->ulid, 'name' => $office->senatorialDistrict->name],
            'lga' => $office->lga === null ? null : ['ulid' => $office->lga->ulid, 'name' => $office->lga->name],
            'ward' => $office->ward === null ? null : ['ulid' => $office->ward->ulid, 'name' => $office->ward->name],
            'constituency_name' => $office->constituency_name,
            'started_on' => $office->started_on->toDateString(),
            'ended_on' => $office->ended_on?->toDateString(),
            'current' => $office->isCurrent(),
            'person' => $withPerson && $office->person !== null
                ? ['ulid' => $office->person->ulid, 'name' => $office->person->name]
                : null,
        ];
    }

    /**
     * @return list<array{value: string, label: string, requiresDistrict: bool, requiresLga: bool, requiresWard: bool}>
     */
    public static function officeTypes(): array
    {
        return array_map(fn (PoliticalOfficeType $type) => [
            'value' => $type->value,
            'label' => $type->label(),
            'requiresDistrict' => $type->requiresDistrict(),
            'requiresLga' => $type->requiresLga(),
            'requiresWard' => $type->requiresWard(),
        ], PoliticalOfficeType::cases());
    }
}
