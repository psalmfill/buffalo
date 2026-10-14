<?php

namespace App\Http\Controllers\Persons;

use App\Http\Controllers\Controller;
use App\Http\Presenters\PersonPresenter;
use App\Http\Requests\Persons\PersonPickerRequest;
use App\Models\Person;
use App\Models\User;
use App\Support\Persons\PersonSearch;
use App\Support\Persons\SearchTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Person picker for forms (associations, traditional rulers, religious
 * leaders): persons the user can see, by name or NIN/phone, optionally
 * only those holding an active role, or only the user's OWN records
 * (`own`: merge requests, Phase 10A). JSON, POSTed.
 */
class PersonPickerController extends Controller
{
    public const LIMIT = 10;

    public function __invoke(PersonPickerRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Person::class);

        $term = SearchTerm::parse((string) $request->validated('q'));
        $role = $request->validated('role');
        $except = $request->validated('except');
        /** @var User $user */
        $user = $request->user();

        $query = Person::query()
            ->with(['lga', 'roles', 'media' => fn ($q) => $q->where('collection_name', Person::PHOTOS)])
            ->when($role, fn (Builder $q, string $role) => $q->whereHas('roles', fn (Builder $r) => $r->where('is_active', true)->whereHas('type', fn (Builder $t) => $t->where('code', $role))))
            ->when($except, fn (Builder $q, string $ulid) => $q->where('persons.ulid', '!=', $ulid))
            ->when($request->boolean('own'), fn (Builder $q) => $q->ownJurisdiction($user));

        $results = $term->isNumber()
            ? PersonSearch::byNumber($query, $term)->limit(self::LIMIT)->get()
            : PersonSearch::withThresholds(PersonSearch::SIMILARITY, PersonSearch::WORD_SIMILARITY, fn () => PersonSearch::byName($query, $term->raw)->limit(self::LIMIT)->get());

        return response()->json([
            'results' => $results->map(fn (Person $person) => PersonPresenter::row($person))->values(),
        ])->header('Cache-Control', 'private, no-store');
    }
}
