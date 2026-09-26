<?php

namespace App\Actions\CriminalGroups;

use App\Models\CriminalGroup;
use App\Models\Lga;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates or edits a criminal group. The home LGA (the owning
 * jurisdiction) is fixed once created: moving it would change who may see
 * the group. Classification changes are logged by HasClassification.
 */
final class SaveCriminalGroup
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Lga $lga, array $data, User $actor): CriminalGroup
    {
        return DB::transaction(function () use ($lga, $data, $actor): CriminalGroup {
            $group = new CriminalGroup;
            $group->forceFill([
                ...self::attributes($data),
                'classification' => $data['classification'],
                'lga_id' => $lga->id,
                'state_id' => $lga->state_id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            return $group;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CriminalGroup $group, array $data, User $actor): CriminalGroup
    {
        return DB::transaction(function () use ($group, $data, $actor): CriminalGroup {
            $group->forceFill([...self::attributes($data), 'classification' => $data['classification']]);

            if ($group->isDirty()) {
                $group->forceFill(['updated_by' => $actor->id])->save();
            }

            return $group;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function attributes(array $data): array
    {
        return [
            'name' => trim((string) $data['name']),
            'other_names' => array_values(array_filter(
                $data['other_names'] ?? [],
                fn (mixed $name): bool => is_string($name) && trim($name) !== '',
            )),
            'type' => $data['type'],
            'description' => trim((string) $data['description']),
            'estimated_size' => isset($data['estimated_size']) ? (int) $data['estimated_size'] : null,
            'status' => $data['status'],
        ];
    }
}
