<?php

namespace App\Actions\Users;

use App\Enums\JurisdictionLevel;
use App\Enums\Role;
use App\Models\GeopoliticalZone;
use App\Models\Lga;
use App\Models\State;
use Illuminate\Validation\ValidationException;

/**
 * A role together with the jurisdiction it applies to. validate() enforces
 * the role/jurisdiction rules of CLAUDE.md section 4 before anything is
 * written; the database constraints on users are the last line of defence.
 */
final readonly class UserJurisdiction
{
    /**
     * @param  list<GeopoliticalZone>  $zones  only for a DDG; empty = national
     */
    public function __construct(
        public Role $role,
        public ?State $state = null,
        public ?Lga $lga = null,
        public array $zones = [],
    ) {}

    public function level(): JurisdictionLevel
    {
        return $this->role->jurisdictionLevel();
    }

    /**
     * @throws ValidationException
     */
    public function validate(): void
    {
        $errors = [];
        $label = $this->role->label();

        switch ($this->level()) {
            case JurisdictionLevel::System:
            case JurisdictionLevel::National:
                if ($this->state !== null) {
                    $errors['state'] = "A {$label} does not have a state jurisdiction.";
                }
                if ($this->lga !== null) {
                    $errors['lga'] = "A {$label} does not have an LGA jurisdiction.";
                }
                break;

            case JurisdictionLevel::State:
                if ($this->state === null) {
                    $errors['state'] = "A {$label} must be assigned a state.";
                } elseif (! $this->state->is_active) {
                    $errors['state'] = 'The selected state is not active.';
                }
                if ($this->lga !== null) {
                    $errors['lga'] = "A {$label} is assigned a state, not an LGA.";
                }
                break;

            case JurisdictionLevel::Lga:
                if ($this->lga === null) {
                    $errors['lga'] = "A {$label} must be assigned an LGA.";
                } elseif (! $this->lga->is_active) {
                    $errors['lga'] = 'The selected LGA is not active.';
                } elseif ($this->state !== null && $this->lga->state_id !== $this->state->id) {
                    $errors['lga'] = 'The selected LGA is not in the selected state.';
                }
                break;
        }

        if ($this->zones !== [] && ! $this->role->allowsZones()) {
            $errors['zones'] = 'Only a Deputy Director General can be restricted to geopolitical zones.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Column values for the users table.
     *
     * @return array{jurisdiction_level: JurisdictionLevel, state_id: int|null, lga_id: int|null}
     */
    public function attributes(): array
    {
        return match ($this->level()) {
            JurisdictionLevel::System, JurisdictionLevel::National => [
                'jurisdiction_level' => $this->level(),
                'state_id' => null,
                'lga_id' => null,
            ],
            JurisdictionLevel::State => [
                'jurisdiction_level' => $this->level(),
                'state_id' => $this->state?->id,
                'lga_id' => null,
            ],
            JurisdictionLevel::Lga => [
                'jurisdiction_level' => $this->level(),
                'state_id' => $this->lga?->state_id,
                'lga_id' => $this->lga?->id,
            ],
        };
    }

    /**
     * @return list<int>
     */
    public function zoneIds(): array
    {
        return $this->role->allowsZones()
            ? array_values(array_unique(array_map(fn (GeopoliticalZone $zone): int => $zone->id, $this->zones)))
            : [];
    }
}
