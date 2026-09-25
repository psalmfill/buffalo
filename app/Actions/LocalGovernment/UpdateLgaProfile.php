<?php

namespace App\Actions\LocalGovernment;

use App\Models\Lga;
use App\Models\LgaProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Saves an LGA's profile (creating it on first edit) and its editable basic
 * facts (headquarters, area, population estimate). Names, codes and parents
 * stay admin/import-only. Both models are audited.
 */
final class UpdateLgaProfile
{
    /**
     * @param  array<string, mixed>  $profile  LgaProfile fillable fields
     * @param  array{headquarters?: string|null, area_km2?: numeric-string|float|int|null, population_estimate?: int|null}  $basics
     */
    public function __invoke(Lga $lga, array $profile, array $basics, User $actor): LgaProfile
    {
        return DB::transaction(function () use ($lga, $profile, $basics, $actor): LgaProfile {
            $record = self::profileFor($lga, $actor);
            $record->fill($profile);

            if ($record->isDirty() || ! $record->exists) {
                $record->forceFill(['updated_by' => $actor->id])->save();
            }

            $lga->forceFill($basics);

            if ($lga->isDirty()) {
                $lga->save();
            }

            return $record;
        });
    }

    /**
     * The LGA's profile, or a new unsaved one bound to the LGA.
     */
    public static function profileFor(Lga $lga, User $actor): LgaProfile
    {
        return LgaProfile::query()->firstWhere('lga_id', $lga->id)
            ?? (new LgaProfile)->forceFill([
                'lga_id' => $lga->id,
                'state_id' => $lga->state_id,
                'terrain' => [],
                'created_by' => $actor->id,
            ]);
    }
}
