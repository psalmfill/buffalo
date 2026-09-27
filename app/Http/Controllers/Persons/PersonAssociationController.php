<?php

namespace App\Http\Controllers\Persons;

use App\Actions\Persons\ManagePersonAssociations;
use App\Enums\AssociationRelationship;
use App\Http\Controllers\Controller;
use App\Http\Requests\Persons\PersonAssociationRequest;
use App\Models\Person;
use App\Models\PersonAssociation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Links between persons. Both persons must be visible to the user: the
 * person in the URL (bound through both scopes, policy `update`) and the
 * other one (bound the same way, policy `view`).
 */
class PersonAssociationController extends Controller
{
    public function store(PersonAssociationRequest $request, Person $person, ManagePersonAssociations $associations): RedirectResponse
    {
        $this->authorize('update', $person);

        /** @var Person $other */
        $other = $request->other();
        /** @var User $actor */
        $actor = $request->user();

        $associations->add(
            $person,
            $other,
            AssociationRelationship::from((string) $request->validated('relationship')),
            $request->validated('notes'),
            $actor,
        );

        return redirect()->route('persons.show', ['person' => $person, 'tab' => 'associations'])
            ->with('toast', ['type' => 'success', 'message' => "Linked with {$other->name}."]);
    }

    public function destroy(Request $request, Person $person, PersonAssociation $association, ManagePersonAssociations $associations): RedirectResponse
    {
        $this->authorize('update', $person);
        abort_unless($association->involves($person), 404);

        // The other person must be visible too: otherwise the link is not
        // shown to this user and cannot be removed by them.
        $other = Person::query()->whereKey($association->otherPersonId($person))->first();
        abort_unless($other instanceof Person, 404);
        // Own jurisdiction: a person seen only under a grant is read-only.
        $this->authorize('link', $other);

        /** @var User $actor */
        $actor = $request->user();
        $associations->remove($association, $person, $other, $actor);

        return redirect()->route('persons.show', ['person' => $person, 'tab' => 'associations'])
            ->with('toast', ['type' => 'success', 'message' => 'Association removed.']);
    }
}
