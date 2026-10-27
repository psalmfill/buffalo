<?php

namespace App\Actions\LocalGovernment;

use App\Models\State;
use App\Models\StateProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Saves a state's profile (creating it on first edit) and its one editable
 * fact, the capital (Phase 11F). Names, codes and zones stay admin and
 * import only; area and population are computed from the LGAs. Both models
 * are audited.
 */
final class UpdateStateProfile
{
    /**
     * @param  array<string, mixed>  $profile  StateProfile fillable fields
     * @param  array{capital?: string|null}  $facts
     */
    public function __invoke(State $state, array $profile, array $facts, User $actor): StateProfile
    {
        return DB::transaction(function () use ($state, $profile, $facts, $actor): StateProfile {
            $record = self::profileFor($state, $actor);
            $record->fill($profile);

            if ($record->isDirty() || ! $record->exists) {
                $record->forceFill(['updated_by' => $actor->id])->save();
            }

            $state->forceFill($facts);

            if ($state->isDirty()) {
                $state->save();
            }

            return $record;
        });
    }

    /**
     * The state's profile, or a new unsaved one bound to the state.
     */
    public static function profileFor(State $state, User $actor): StateProfile
    {
        return StateProfile::query()->firstWhere('state_id', $state->id)
            ?? (new StateProfile)->forceFill([
                'state_id' => $state->id,
                'terrain' => [],
                'created_by' => $actor->id,
            ]);
    }
}
