<?php

namespace App\Actions\CommunityInformation;

use App\Models\Community;
use App\Models\Lga;
use App\Models\User;
use App\Models\VulnerablePopulationStat;

/**
 * Records vulnerable population figures (aggregate counts only) for an LGA
 * or one of its communities, or corrects a recorded figure. New figures
 * are new rows: history is kept.
 */
final class SaveVulnerableFigures
{
    /**
     * @param  array{community: Community|null, category: string, adult_male: int, adult_female: int, child_male: int, child_female: int, as_of_date: string, source: string}  $data
     */
    public function create(Lga $lga, array $data, User $actor): VulnerablePopulationStat
    {
        $stat = new VulnerablePopulationStat;
        $stat->forceFill([
            ...self::attributes($data),
            'lga_id' => $lga->id,
            'state_id' => $lga->state_id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ])->save();

        return $stat;
    }

    /**
     * @param  array{community: Community|null, category: string, adult_male: int, adult_female: int, child_male: int, child_female: int, as_of_date: string, source: string}  $data
     */
    public function update(VulnerablePopulationStat $stat, array $data, User $actor): void
    {
        $stat->forceFill(self::attributes($data));

        if ($stat->isDirty()) {
            $stat->forceFill(['updated_by' => $actor->id])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function attributes(array $data): array
    {
        return [
            'community_id' => $data['community']?->id,
            'category' => $data['category'],
            'adult_male' => $data['adult_male'],
            'adult_female' => $data['adult_female'],
            'child_male' => $data['child_male'],
            'child_female' => $data['child_female'],
            'as_of_date' => $data['as_of_date'],
            'source' => trim((string) $data['source']),
        ];
    }
}
