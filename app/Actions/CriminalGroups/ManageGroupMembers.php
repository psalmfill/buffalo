<?php

namespace App\Actions\CriminalGroups;

use App\Models\CriminalGroup;
use App\Models\CriminalGroupMember;
use App\Models\Person;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Adds, edits and removes group members. The person must be visible to
 * the actor (checked by the request). Adding a member never makes them a
 * person of interest; the UI suggests it. Membership changes are logged on
 * the group and on the person, with ULIDs only: a history reader may see
 * the group but not the person, or the reverse.
 */
final class ManageGroupMembers
{
    /**
     * @param  array{role_in_group?: string|null, membership_status: string, since?: string|null, notes?: string|null}  $data
     */
    public function add(CriminalGroup $group, Person $person, array $data, User $actor): CriminalGroupMember
    {
        return DB::transaction(function () use ($group, $person, $data, $actor): CriminalGroupMember {
            $member = new CriminalGroupMember($data);
            $member->forceFill([
                'criminal_group_id' => $group->id,
                'person_id' => $person->id,
                'created_by' => $actor->id,
            ])->save();

            AuditLog::event('member_added', 'Member added', $group, ['person' => $person->ulid, 'membership_status' => $data['membership_status']], $actor);
            AuditLog::event('group_membership_added', 'Added to a criminal group', $person, ['group' => $group->ulid], $actor);

            return $member;
        });
    }

    /**
     * @param  array{role_in_group?: string|null, membership_status: string, since?: string|null, notes?: string|null}  $data
     */
    public function update(CriminalGroupMember $member, array $data): void
    {
        $member->fill($data);

        if ($member->isDirty()) {
            $member->save();
        }
    }

    public function remove(CriminalGroupMember $member, Person $person, User $actor): void
    {
        DB::transaction(function () use ($member, $person, $actor): void {
            AuditLog::event('member_removed', 'Member removed', $member->group, ['person' => $person->ulid], $actor);
            AuditLog::event('group_membership_removed', 'Removed from a criminal group', $person, ['group' => $member->group->ulid], $actor);
            $member->delete();
        });
    }
}
