<?php

namespace App\Http\Controllers\CriminalGroups;

use App\Actions\CriminalGroups\ManageGroupMembers;
use App\Http\Controllers\Controller;
use App\Http\Requests\CriminalGroups\GroupMemberRequest;
use App\Models\CriminalGroup;
use App\Models\CriminalGroupMember;
use App\Models\Person;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Group members: only persons the user can see may be added; a member
 * whose person the user cannot see is not shown to them and cannot be
 * changed by them. {group} is bound through its scopes; {member} must
 * belong to it.
 */
class GroupMemberController extends Controller
{
    public function store(GroupMemberRequest $request, CriminalGroup $group, ManageGroupMembers $members): RedirectResponse
    {
        $this->authorize('update', $group);

        /** @var Person $person */
        $person = $request->person();
        /** @var User $actor */
        $actor = $request->user();
        $members->add($group, $person, $request->values(), $actor);

        return redirect()->route('criminal-groups.show', ['group' => $group, 'tab' => 'members'])
            ->with('toast', ['type' => 'success', 'message' => "{$person->name} added."]);
    }

    public function update(GroupMemberRequest $request, CriminalGroup $group, CriminalGroupMember $member, ManageGroupMembers $members): RedirectResponse
    {
        $this->authorize('update', $group);
        $this->visiblePerson($group, $member, $request);

        $members->update($member, $request->values());

        return redirect()->route('criminal-groups.show', ['group' => $group, 'tab' => 'members'])
            ->with('toast', ['type' => 'success', 'message' => 'Membership saved.']);
    }

    public function destroy(Request $request, CriminalGroup $group, CriminalGroupMember $member, ManageGroupMembers $members): RedirectResponse
    {
        $this->authorize('update', $group);
        $person = $this->visiblePerson($group, $member, $request);

        /** @var User $actor */
        $actor = $request->user();
        $members->remove($member, $person, $actor);

        return redirect()->route('criminal-groups.show', ['group' => $group, 'tab' => 'members'])
            ->with('toast', ['type' => 'success', 'message' => 'Member removed.']);
    }

    private function visiblePerson(CriminalGroup $group, CriminalGroupMember $member, Request $request): Person
    {
        abort_unless($member->criminal_group_id === $group->id, 404);
        $person = Person::query()->find($member->person_id);
        abort_unless($person instanceof Person && $request->user()?->can('view', $person), 404);
        // A person seen only under a grant is read-only.
        abort_unless($request->user()->can('link', $person), 403);

        return $person;
    }
}
