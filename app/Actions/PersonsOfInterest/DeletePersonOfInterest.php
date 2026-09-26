<?php

namespace App\Actions\PersonsOfInterest;

use App\Actions\CommunityInformation\DeleteWithReason;
use App\Enums\PersonRoleType;
use App\Models\Person;
use App\Models\PersonOfInterest;
use App\Models\User;
use App\Support\Persons\RoleAssigner;
use Illuminate\Support\Facades\DB;

/**
 * Soft-deletes a POI record with a reason (State Director and above) and
 * deactivates the person's person_of_interest role. The person remains.
 */
final class DeletePersonOfInterest
{
    public function __construct(
        private readonly DeleteWithReason $delete,
        private readonly RoleAssigner $roles,
    ) {}

    public function __invoke(PersonOfInterest $poi, string $reason, User $actor): void
    {
        DB::transaction(function () use ($poi, $reason, $actor): void {
            $person = Person::query()->find($poi->person_id);
            ($this->delete)($poi, 'person_of_interest', $reason, $actor);

            if ($person !== null) {
                $this->roles->deactivate($person, PersonRoleType::PersonOfInterest);
            }
        });
    }
}
