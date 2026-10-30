<?php

namespace App\Actions\Hierarchy\Admin;

use App\Actions\Hierarchy\HierarchyRules;
use App\Models\Lga;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a ward on screen in an active LGA, under the import's rules: a
 * code of 1–20 uppercase letters, digits or hyphens and a name, each unique
 * within the LGA (the name case-insensitively), compared with every ward of
 * the LGA, active or not. The code can never change afterwards.
 */
final class CreateWard
{
    /**
     * @throws ValidationException
     */
    public function __invoke(Lga $lga, string $code, string $name, string $reason, User $actor): Ward
    {
        $reason = HierarchyChange::reason($reason);
        $name = HierarchyChange::name($name);
        $code = trim($code);

        if (! $lga->is_active) {
            throw ValidationException::withMessages(['lga' => "{$lga->name} is deactivated; reactivate it before adding wards."]);
        }

        if (! HierarchyRules::validWardCode($code)) {
            throw ValidationException::withMessages(['code' => 'The code must be 1–20 uppercase letters, digits or hyphens, starting with a letter or digit.']);
        }

        if (Ward::query()->where('lga_id', $lga->id)->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => "{$lga->name} already has a ward with the code {$code}."]);
        }

        if (Ward::query()->where('lga_id', $lga->id)->whereRaw('lower(name) = lower(?)', [$name])->exists()) {
            throw ValidationException::withMessages(['name' => "{$lga->name} already has a ward named \"{$name}\"."]);
        }

        return DB::transaction(function () use ($lga, $code, $name, $reason, $actor): Ward {
            $ward = new Ward;
            $ward->forceFill([
                'lga_id' => $lga->id,
                'state_id' => $lga->state_id,
                'code' => $code,
                'name' => $name,
                'is_active' => true,
                'origin' => Ward::ORIGIN_ADMIN,
                'created_by' => $actor->id,
                'created_reason' => $reason,
            ])->save();

            HierarchyChange::record($ward, 'created', $reason, $actor, ['lga' => $lga->code]);

            return $ward;
        });
    }
}
