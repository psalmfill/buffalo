<?php

namespace App\Http\Requests\Users;

use App\Actions\Users\UserJurisdiction;
use App\Enums\Role;
use App\Models\GeopoliticalZone;
use App\Models\Lga;
use App\Models\State;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared role/jurisdiction input: role, state, lga (ULIDs) and zones
 * (ULIDs). Whether the combination is valid for the role is checked by
 * UserJurisdiction::validate() (errors keyed role/state/lga/zones).
 */
abstract class UserJurisdictionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function jurisdictionRules(): array
    {
        return [
            'role' => ['required', Rule::enum(Role::class)],
            'state' => ['nullable', 'string', 'exists:states,ulid'],
            'lga' => ['nullable', 'string', 'exists:lgas,ulid'],
            'zones' => ['nullable', 'array'],
            'zones.*' => ['string', 'distinct', 'exists:geopolitical_zones,ulid'],
        ];
    }

    public function jurisdiction(): UserJurisdiction
    {
        $state = $this->filled('state') ? State::query()->where('ulid', $this->string('state')->toString())->first() : null;
        $lga = $this->filled('lga') ? Lga::query()->where('ulid', $this->string('lga')->toString())->first() : null;
        /** @var list<string> $zoneUlids */
        $zoneUlids = array_values(array_filter((array) $this->input('zones', []), 'is_string'));

        return new UserJurisdiction(
            Role::from($this->string('role')->toString()),
            $state,
            $lga,
            array_values(GeopoliticalZone::query()->whereIn('ulid', $zoneUlids)->get()->all()),
        );
    }
}
