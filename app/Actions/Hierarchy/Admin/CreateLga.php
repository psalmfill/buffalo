<?php

namespace App\Actions\Hierarchy\Admin;

use App\Actions\Hierarchy\HierarchyRules;
use App\Models\Lga;
use App\Models\SenatorialDistrict;
use App\Models\State;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates an LGA on screen, under the import's rules: an active district of
 * the same state, a code of exactly three uppercase letters unique within
 * the state, and a name unique within the state (case-insensitive), both
 * compared with every LGA of the state, active or not. The code can never
 * change afterwards: incident references and the import depend on it.
 */
final class CreateLga
{
    /**
     * @throws ValidationException
     */
    public function __invoke(State $state, SenatorialDistrict $district, string $code, string $name, ?string $headquarters, string $reason, User $actor): Lga
    {
        $reason = HierarchyChange::reason($reason);
        $name = HierarchyChange::name($name);
        $headquarters = $headquarters === null || trim($headquarters) === '' ? null : HierarchyChange::name($headquarters, 'headquarters');
        $code = trim($code);

        if ($district->state_id !== $state->id) {
            throw ValidationException::withMessages(['district' => "The senatorial district must be in {$state->name}."]);
        }

        if (! $district->is_active) {
            throw ValidationException::withMessages(['district' => "{$district->name} is deactivated; reactivate it first or choose another district."]);
        }

        if (! HierarchyRules::validLgaCode($code)) {
            throw ValidationException::withMessages(['code' => 'The code must be exactly 3 uppercase letters.']);
        }

        if (Lga::query()->where('state_id', $state->id)->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => "{$state->name} already has an LGA with the code {$code}."]);
        }

        if (Lga::query()->where('state_id', $state->id)->whereRaw('lower(name) = lower(?)', [$name])->exists()) {
            throw ValidationException::withMessages(['name' => "{$state->name} already has an LGA named \"{$name}\"."]);
        }

        return DB::transaction(function () use ($state, $district, $code, $name, $headquarters, $reason, $actor): Lga {
            $lga = new Lga;
            $lga->forceFill([
                'state_id' => $state->id,
                'senatorial_district_id' => $district->id,
                'code' => $code,
                'name' => $name,
                'headquarters' => $headquarters,
                'is_active' => true,
                'origin' => Lga::ORIGIN_ADMIN,
                'created_by' => $actor->id,
                'created_reason' => $reason,
            ])->save();

            HierarchyChange::record($lga, 'created', $reason, $actor, ['state' => $state->code, 'district' => $district->code]);

            return $lga;
        });
    }
}
