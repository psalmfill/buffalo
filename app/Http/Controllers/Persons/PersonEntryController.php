<?php

namespace App\Http\Controllers\Persons;

use App\Actions\Persons\SavePerson;
use App\Enums\MatchContext;
use App\Http\Controllers\Controller;
use App\Http\Presenters\AccessRequestPresenter;
use App\Http\Presenters\PersonPresenter;
use App\Http\Requests\Persons\PersonRequest;
use App\Models\Lga;
use App\Models\Person;
use App\Models\RecordAccessRequest;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\CrossJurisdiction\MatchCriteria;
use App\Support\CrossJurisdiction\PersonMatchService;
use App\Support\Persons\PhoneNumber;
use App\Support\Persons\SimilarPersons;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * People entered from a record's form (incidents, Phase 5A; arrests,
 * Phase 11C). Both endpoints run the cross-jurisdiction match with the
 * form's context:
 *
 * - check: before adding an unidentified person (optionally with a known
 *   name, date of birth, NIN or phone, used for matching only and never
 *   stored) or a new person: persons the user may record (the form's link
 *   ability), and minimal hits elsewhere ("Request access").
 * - store: creates a new person record (the person form's rules), refusing
 *   hits without a justification ("Create anyway", audited). JSON, so the
 *   form stays open.
 */
abstract class PersonEntryController extends Controller
{
    abstract protected function context(): MatchContext;

    /** The PersonPolicy ability for recording a person on the form's record. */
    abstract protected function linkAbility(): string;

    /** Authorises use of the form (creating its record). */
    abstract protected function authorizeEntry(): void;

    /** "Created from …" in the audit event. */
    abstract protected function createdFrom(): string;

    public function check(Request $request, SimilarPersons $similar, PersonMatchService $matches): JsonResponse
    {
        $this->authorizeEntry();
        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'other_names' => ['nullable', 'string', 'max:150'],
            'nin' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:30'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $user = $this->actor($request);

        $local = [];
        if (trim(($data['first_name'] ?? '').($data['last_name'] ?? '')) !== '') {
            $local = array_values(array_filter(
                $similar->find([
                    'first_name' => (string) ($data['first_name'] ?? ''),
                    'last_name' => (string) ($data['last_name'] ?? ''),
                    'other_names' => $data['other_names'] ?? null,
                    'nin' => isset($data['nin']) ? (preg_replace('/\s+/', '', (string) $data['nin']) ?: null) : null,
                    'phone' => isset($data['phone']) ? PhoneNumber::normalise((string) $data['phone']) : null,
                    'date_of_birth' => $data['date_of_birth'] ?? null,
                ]),
                fn (array $match) => $user->can($this->linkAbility(), $match['person']),
            ));
        }

        $criteria = MatchCriteria::fromPersonInput($data);
        $cross = ! $criteria->isEmpty() && $user->can('create', RecordAccessRequest::class)
            ? $matches->match($criteria, $user, $this->context())
            : null;

        return response()->json([
            'matches' => array_map(fn (array $m) => [...PersonPresenter::row($m['person']), 'reasons' => $m['reasons']], $local),
            'hits' => $cross?->hitsArray() ?? [],
            'hitLimitReached' => $cross !== null && $cross->limitReached,
            'accessOptions' => AccessRequestPresenter::options(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(PersonRequest $request, SavePerson $save, PersonMatchService $matches): JsonResponse
    {
        $this->authorize('create', Person::class);
        $this->authorizeEntry();
        $user = $this->actor($request);
        $values = $request->values();

        $hitCount = $user->can('create', RecordAccessRequest::class)
            ? count($matches->match(MatchCriteria::fromPersonInput($values), $user, $this->context(), record: false)->hits)
            : 0;
        $justification = trim((string) $request->validated('hit_justification'));

        if ($hitCount > 0 && $justification === '') {
            throw ValidationException::withMessages([
                'hit_justification' => 'This person may be on record in another jurisdiction. Request access, or explain why a new record is needed.',
            ]);
        }

        /** @var Lga $lga */
        $lga = $request->lga();

        $person = DB::transaction(function () use ($save, $lga, $request, $values, $user, $hitCount, $justification): Person {
            $person = $save->create($lga, $request->community(), $values, $request->roles(), $user);

            if ($hitCount > 0) {
                AuditLog::event('created_despite_hit', "Created from {$this->createdFrom()} although matching records exist in other jurisdictions", $person, [
                    'justification' => $justification,
                    'hits' => $hitCount,
                    'context' => $this->context()->value,
                ], $user);
            }

            return $person;
        });

        return response()->json(['person' => ['value' => $person->ulid, 'label' => $person->name]], 201);
    }

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
