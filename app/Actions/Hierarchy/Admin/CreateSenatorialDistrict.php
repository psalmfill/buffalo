<?php

namespace App\Actions\Hierarchy\Admin;

use App\Actions\Hierarchy\HierarchyRules;
use App\Models\SenatorialDistrict;
use App\Models\State;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a senatorial district on screen, under the import's rules: code
 * `<STATE>-<X>`, globally unique (any district, active or not), and a name
 * unique within the state (case-insensitive). The code can never change
 * afterwards: it is the import's matching key.
 */
final class CreateSenatorialDistrict
{
    /**
     * @throws ValidationException
     */
    public function __invoke(State $state, string $code, string $name, string $reason, User $actor): SenatorialDistrict
    {
        $reason = HierarchyChange::reason($reason);
        $name = HierarchyChange::name($name);
        $code = trim($code);

        if (! HierarchyRules::validDistrictCode($code, $state->code)) {
            throw ValidationException::withMessages(['code' => "The code must be the state code, a hyphen and uppercase letters or digits (e.g. {$state->code}-C), at most 20 characters."]);
        }

        if (SenatorialDistrict::query()->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => "The code {$code} is already used by another senatorial district."]);
        }

        if (SenatorialDistrict::query()->where('state_id', $state->id)->whereRaw('lower(name) = lower(?)', [$name])->exists()) {
            throw ValidationException::withMessages(['name' => "{$state->name} already has a senatorial district named \"{$name}\"."]);
        }

        return DB::transaction(function () use ($state, $code, $name, $reason, $actor): SenatorialDistrict {
            $district = new SenatorialDistrict;
            $district->forceFill([
                'state_id' => $state->id,
                'code' => $code,
                'name' => $name,
                'is_active' => true,
                'origin' => SenatorialDistrict::ORIGIN_ADMIN,
                'created_by' => $actor->id,
                'created_reason' => $reason,
            ])->save();

            HierarchyChange::record($district, 'created', $reason, $actor, ['state' => $state->code]);

            return $district;
        });
    }
}
