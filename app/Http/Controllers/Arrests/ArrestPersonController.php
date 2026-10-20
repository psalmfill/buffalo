<?php

namespace App\Http\Controllers\Arrests;

use App\Enums\MatchContext;
use App\Http\Controllers\Persons\PersonEntryController;
use App\Models\Arrest;
use App\Models\Person;
use App\Support\Arrests\ArrestSuggestions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * People entered from the arrest form (Phase 11C): match context
 * `arrest_entry`; persons the user may record need `linkToArrest`.
 */
class ArrestPersonController extends PersonEntryController
{
    protected function context(): MatchContext
    {
        return MatchContext::ArrestEntry;
    }

    protected function linkAbility(): string
    {
        return 'linkToArrest';
    }

    protected function authorizeEntry(): void
    {
        $this->authorize('create', Arrest::class);
    }

    protected function createdFrom(): string
    {
        return 'an arrest';
    }

    /**
     * Suggestions for persons picked on the arrest form (POI status, or
     * designation), for persons the user may record. JSON.
     */
    public function suggestions(Request $request): JsonResponse
    {
        $this->authorizeEntry();
        $data = $request->validate(['persons' => ['required', 'array', 'max:50'], 'persons.*' => ['string', 'size:26']]);
        $user = $this->actor($request);

        $suggestions = Person::query()->whereIn('persons.ulid', $data['persons'])->get()
            ->filter(fn (Person $person) => $user->can('linkToArrest', $person))
            ->mapWithKeys(fn (Person $person) => [$person->ulid => ArrestSuggestions::for($person, $user)]);

        return response()->json(['suggestions' => $suggestions])->header('Cache-Control', 'private, no-store');
    }
}
