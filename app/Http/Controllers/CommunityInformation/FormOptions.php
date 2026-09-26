<?php

namespace App\Http\Controllers\CommunityInformation;

use App\Models\Community;
use App\Models\Lga;

/**
 * Options shared by the community information forms.
 */
trait FormOptions
{
    /**
     * The LGA's communities as select options (through the scope).
     *
     * @return list<array{value: string, label: string}>
     */
    private function communityOptions(Lga $lga): array
    {
        return array_values(Community::query()->where('lga_id', $lga->id)->orderBy('name')->get(['ulid', 'name'])
            ->map(fn (Community $community): array => ['value' => $community->ulid, 'label' => $community->name])
            ->all());
    }
}
