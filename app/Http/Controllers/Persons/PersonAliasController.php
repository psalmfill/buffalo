<?php

namespace App\Http\Controllers\Persons;

use App\Actions\Persons\ManagePersonAliases;
use App\Http\Controllers\Controller;
use App\Http\Requests\Persons\PersonAliasRequest;
use App\Models\Person;
use App\Models\PersonAlias;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A person's aliases. {person} is bound through both scopes; {alias} must
 * belong to it (scoped binding).
 */
class PersonAliasController extends Controller
{
    public function store(PersonAliasRequest $request, Person $person, ManagePersonAliases $aliases): RedirectResponse
    {
        $this->authorize('update', $person);

        /** @var User $actor */
        $actor = $request->user();
        $aliases->add($person, (string) $request->validated('alias'), $actor);

        return redirect()->route('persons.show', ['person' => $person, 'tab' => 'aliases'])
            ->with('toast', ['type' => 'success', 'message' => 'Alias added.']);
    }

    public function destroy(Request $request, Person $person, PersonAlias $alias, ManagePersonAliases $aliases): RedirectResponse
    {
        $this->authorize('update', $person);
        abort_unless($alias->person_id === $person->id, 404);

        /** @var User $actor */
        $actor = $request->user();
        $aliases->remove($alias, $actor);

        return redirect()->route('persons.show', ['person' => $person, 'tab' => 'aliases'])
            ->with('toast', ['type' => 'success', 'message' => 'Alias removed.']);
    }
}
