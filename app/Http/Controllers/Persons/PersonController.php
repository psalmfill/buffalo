<?php

namespace App\Http\Controllers\Persons;

use App\Actions\CommunityInformation\DeleteWithReason;
use App\Actions\Persons\AddPersonPhoto;
use App\Actions\Persons\SavePerson;
use App\Enums\GrantScope;
use App\Enums\MatchContext;
use App\Http\Controllers\Controller;
use App\Http\Presenters\AccessRequestPresenter;
use App\Http\Presenters\ActivityPresenter;
use App\Http\Presenters\CriminalGroupPresenter;
use App\Http\Presenters\MergePresenter;
use App\Http\Presenters\PersonPresenter;
use App\Http\Presenters\PoiPresenter;
use App\Http\Presenters\PoliticalPresenter;
use App\Http\Requests\LocalGovernment\DeleteRecordRequest;
use App\Http\Requests\Persons\PersonRequest;
use App\Http\Requests\Persons\PersonSearchRequest;
use App\Models\Community;
use App\Models\CriminalGroup;
use App\Models\CriminalGroupMember;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\Media;
use App\Models\Person;
use App\Models\PersonAlias;
use App\Models\PersonAssociation;
use App\Models\PersonMerge;
use App\Models\PersonMergeRequest;
use App\Models\PersonOfInterest;
use App\Models\PersonRole;
use App\Models\PoliticalOffice;
use App\Models\RecordAccessGrant;
use App\Models\RecordAccessRequest;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\CrossJurisdiction\MatchCriteria;
use App\Support\CrossJurisdiction\MatchResult;
use App\Support\CrossJurisdiction\PersonMatchService;
use App\Support\Incidents\IncidentLinks;
use App\Support\Jurisdiction\AccessRequestParties;
use App\Support\Jurisdiction\GrantAccessResolver;
use App\Support\Jurisdiction\GrantedPersonView;
use App\Support\Jurisdiction\JurisdictionGate;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Persons\PersonSearch;
use App\Support\Persons\PhoneNumber;
use App\Support\Persons\SearchTerm;
use App\Support\Persons\SimilarPersons;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Persons (Phase 3A): search, create (with a duplicate check), the person
 * page, edit and soft delete with a reason.
 *
 * {person} is bound through the jurisdiction and classification scopes, so
 * anything else is 404. Every person page view is access-logged. Pages
 * showing persons use Inertia's encrypted history, so names and protected
 * fields are not left readable in the browser's history state. Searches are
 * POSTed: names, NINs and phone numbers never appear in URLs.
 */
class PersonController extends Controller
{
    /** Tabs of the person page (3B adds political offices, POI details and group memberships; Phase 5 linked incidents). */
    public const TABS = ['profile', 'aliases', 'associations', 'photos', 'political_offices', 'poi', 'group_memberships', 'incidents', 'history'];

    /** Search results shown at most. */
    public const RESULT_LIMIT = 50;

    /** Persons shown for an empty search. */
    public const RECENT_LIMIT = 20;

    public function index(Request $request, JurisdictionOptions $options, PersonMatchService $matches): Response
    {
        $this->authorize('viewAny', Person::class);

        return $this->searchPage($this->actor($request), $options, $matches, ['q' => '', 'role' => '', 'state' => '', 'lga' => '', 'gender' => '', 'classification' => '', 'dob' => '']);
    }

    public function search(PersonSearchRequest $request, JurisdictionOptions $options, PersonMatchService $matches): Response
    {
        $this->authorize('viewAny', Person::class);

        return $this->searchPage($this->actor($request), $options, $matches, $request->filters());
    }

    public function create(Request $request, JurisdictionOptions $options): Response
    {
        $this->authorize('create', Person::class);

        return $this->form($this->actor($request), $options, null, $request->query('lga'));
    }

    /**
     * Possible duplicates of the person being created, among those the user
     * can see (JSON, POSTed: the values include NIN and phone).
     */
    public function similar(Request $request, SimilarPersons $similar, PersonMatchService $matches): JsonResponse
    {
        $this->authorize('create', Person::class);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'other_names' => ['nullable', 'string', 'max:150'],
            'nin' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:30'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $actor = $this->actor($request);
        // Records elsewhere (Phase 3C): minimal hits, officers who may request access only.
        $cross = $actor->can('create', RecordAccessRequest::class)
            ? $matches->match(MatchCriteria::fromPersonInput($data), $actor, MatchContext::PersonCreate)
            : null;

        return response()->json([
            'matches' => $this->similarMatches($similar, $data),
            'hits' => $cross?->hitsArray() ?? [],
            'hitLimitReached' => $cross !== null && $cross->limitReached,
            'accessOptions' => AccessRequestPresenter::options(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(PersonRequest $request, SavePerson $save, AddPersonPhoto $addPhoto, SimilarPersons $similar, PersonMatchService $matches): RedirectResponse
    {
        $this->authorize('create', Person::class);
        $actor = $this->actor($request);
        $values = $request->values();

        if (! $request->boolean('confirm_new') && $this->similarMatches($similar, $values) !== []) {
            throw ValidationException::withMessages([
                'confirm_new' => 'Possible duplicates were found. Review them before creating a new record.',
            ]);
        }

        // Creating although the person is on record elsewhere needs a
        // justification (re-checked here without recording new events).
        $hitCount = $actor->can('create', RecordAccessRequest::class)
            ? count($matches->match(MatchCriteria::fromPersonInput($values), $actor, MatchContext::PersonCreate, record: false)->hits)
            : 0;
        $justification = trim((string) $request->validated('hit_justification'));

        if ($hitCount > 0 && $justification === '') {
            throw ValidationException::withMessages([
                'hit_justification' => 'This person may be on record in another jurisdiction. Request access, or explain why a new record is needed.',
            ]);
        }

        /** @var Lga $lga */
        $lga = $request->lga();
        $photo = $request->file('photo');

        $person = DB::transaction(function () use ($save, $addPhoto, $lga, $request, $values, $actor, $photo, $hitCount, $justification): Person {
            $person = $save->create($lga, $request->community(), $values, $request->roles(), $actor);

            if ($photo instanceof UploadedFile) {
                $addPhoto($person, $photo, $actor);
            }

            if ($hitCount > 0) {
                AuditLog::event('created_despite_hit', 'Created although matching records exist in other jurisdictions', $person, [
                    'justification' => $justification,
                    'hits' => $hitCount,
                ], $actor);
            }

            return $person;
        });

        return redirect()->route('persons.show', $person)
            ->with('toast', ['type' => 'success', 'message' => "{$person->name} added."]);
    }

    /** Person page fields a grant shows, per scope (Phase 3C). */
    public const GRANT_PROFILE_FIELDS = [
        'ulid', 'name', 'first_name', 'last_name', 'other_names', 'gender', 'date_of_birth', 'approximate_age',
        'nationality', 'occupation', 'description', 'roles', 'all_roles', 'classification', 'is_deceased', 'thumbnail',
    ];

    public const GRANT_FULL_EXTRA_FIELDS = ['nin', 'phone', 'address'];

    /** Tabs a grant shows, per scope. */
    public const GRANT_TABS = [
        'profile' => ['profile'],
        'full' => ['profile', 'associations', 'political_offices', 'poi', 'group_memberships'],
    ];

    public function show(Request $request, Person $person, JurisdictionGate $gate, GrantAccessResolver $grants, GrantedPersonView $grantedView): Response
    {
        $this->authorize('view', $person);
        $actor = $this->actor($request);

        // Visible only through a cross-jurisdiction grant: read-only, per scope.
        if ($gate->viaGrantOnly($actor, $person)) {
            $grant = $grants->activeGrant($actor, $person);
            abort_if($grant === null, 404);

            return $this->showUnderGrant($request, $person, $actor, $grant, $grantedView);
        }

        // Incidents involving the person (Phase 5D), hidden when there are none.
        // Never shown under a grant: only the person is grantable.
        $hasIncidents = $actor->can('viewAny', Incident::class) && IncidentLinks::person($person)->exists();
        $tabs = $hasIncidents ? self::TABS : array_values(array_diff(self::TABS, ['incidents']));
        $tab = in_array($request->query('tab'), $tabs, true) ? (string) $request->query('tab') : 'profile';

        // Every view of a person is access-logged (CLAUDE.md section 6).
        $person->recordAccess(context: "tab:{$tab}");

        Inertia::encryptHistory();

        $person->load(['lga.state', 'state', 'community', 'roles', 'media' => fn ($q) => $q->where('collection_name', Person::PHOTOS)]);
        /** @var \Illuminate\Support\Collection<int, Media> $photos */
        $photos = Person::photosCurrentFirst($person->media);
        $current = $photos->first();

        return Inertia::render('persons/show', [
            'person' => PersonPresenter::detail($person),
            'photo' => $current instanceof Media ? PersonPresenter::photo($person, $current, true) : null,
            'tab' => $tab,
            'tabs' => $tabs,
            'incidents' => $tab === 'incidents' ? $this->incidents($person) : null,
            'options' => PersonPresenter::options(),
            'aliases' => $tab === 'aliases' || $tab === 'profile'
                ? $person->aliases()->orderBy('alias')->get()->map(fn (PersonAlias $alias) => PersonPresenter::alias($alias))->all()
                : null,
            'associations' => $tab === 'associations' ? $this->associations($person) : null,
            'photos' => $tab === 'photos'
                ? $photos->map(fn (Media $media) => PersonPresenter::photo($person, $media, $media->is($current)))->all()
                : null,
            'history' => $tab === 'history' ? $this->history($person) : null,
            'politicalOffices' => $tab === 'political_offices' && $actor->can('viewAny', PoliticalOffice::class) ? $this->offices($person) : null,
            'poi' => $tab === 'poi' && $actor->can('viewAny', PersonOfInterest::class) ? $this->poi($person, $actor) : null,
            'groupMemberships' => $tab === 'group_memberships' && $actor->can('viewAny', CriminalGroup::class)
                ? CriminalGroupMember::query()
                    ->where('person_id', $person->id)
                    ->with(['group.lga', 'group.state'])
                    ->latest('id')
                    ->get()
                    ->map(fn (CriminalGroupMember $m) => CriminalGroupPresenter::member($m, withGroup: true))
                    ->values()
                : null,
            'poiOptions' => $tab === 'poi' ? PoiPresenter::options() : null,
            'grant' => null,
            // Records merged into this one (Phase 10A), newest first.
            'merges' => PersonMerge::query()->with(['source'])
                ->where('survivor_person_id', $person->id)
                ->latest('id')
                ->get()
                ->filter(fn (PersonMerge $merge) => $actor->can('view', $merge))
                ->map(fn (PersonMerge $merge) => MergePresenter::merged($merge, $actor, $person))
                ->values(),
            'can' => [
                'requestMerge' => $actor->can('create', PersonMergeRequest::class) && $actor->can('requestMerge', $person),
                'merge' => $actor->can('merge', $person),
                'update' => $actor->can('update', $person),
                'delete' => $actor->can('delete', $person),
                'viewPolitical' => $actor->can('viewAny', PoliticalOffice::class),
                'addOffice' => $actor->can('create', [PoliticalOffice::class, $person]),
                'viewPoi' => $actor->can('viewAny', PersonOfInterest::class),
                'designatePoi' => $actor->can('create', [PersonOfInterest::class, $person]),
                'viewGroups' => $actor->can('viewAny', CriminalGroup::class),
            ],
        ]);
    }

    /**
     * The person page for a user whose only access is a grant (Phase 3C):
     * every view is access-logged with context "grant"; only the grant
     * scope's fields and tabs; nothing editable; nothing linked.
     */
    private function showUnderGrant(Request $request, Person $person, User $actor, RecordAccessGrant $grant, GrantedPersonView $grantedView): Response
    {
        $full = $grant->scope === GrantScope::Full;
        $tabs = self::GRANT_TABS[$grant->scope->value];
        $tab = in_array($request->query('tab'), $tabs, true) ? (string) $request->query('tab') : 'profile';

        $person->recordAccess(context: 'grant');
        Inertia::encryptHistory();

        $person->load(['roles', 'media' => fn ($q) => $q->where('collection_name', Person::PHOTOS)]);
        $current = Person::photosCurrentFirst($person->media)->first();
        $fields = [...self::GRANT_PROFILE_FIELDS, ...($full ? self::GRANT_FULL_EXTRA_FIELDS : [])];
        $extra = $full ? $grantedView->full($person, $actor, $grant) : null;
        $grant->loadMissing(['request.owningState']);
        AccessRequestParties::attach($grant);

        return Inertia::render('persons/show', [
            'person' => [
                ...array_intersect_key(PersonPresenter::detail($person), array_flip($fields)),
                'state' => null,
                'lga' => null,
                'community' => null,
            ],
            'photo' => $current instanceof Media ? PersonPresenter::photo($person, $current, true) : null,
            'tab' => $tab,
            'tabs' => $tabs,
            'options' => PersonPresenter::options(),
            'aliases' => $person->aliases()->orderBy('alias')->get()->map(fn (PersonAlias $alias) => PersonPresenter::alias($alias))->all(),
            'associations' => null,
            'grantAssociations' => $tab === 'associations' ? $extra['associations'] ?? [] : null,
            'photos' => null,
            'history' => null,
            'politicalOffices' => $tab === 'political_offices' ? $extra['politicalOffices'] ?? null : null,
            'poi' => $tab === 'poi' ? $extra['poi'] ?? null : null,
            'groupMemberships' => null,
            'grantMemberships' => $tab === 'group_memberships' ? $extra['groupMemberships'] ?? [] : null,
            'poiOptions' => null,
            'merges' => [],
            'grant' => [
                'ulid' => $grant->ulid,
                'scope' => ['value' => $grant->scope->value, 'label' => $grant->scope->label()],
                'grantedBy' => $grant->granter->name,
                'state' => $grant->request->owningState->name,
                'expiresAt' => $grant->expires_at->toIso8601String(),
            ],
            'can' => [
                'requestMerge' => false,
                'merge' => false,
                'update' => false,
                'delete' => false,
                'viewPolitical' => $full,
                'addOffice' => false,
                'viewPoi' => $full,
                'designatePoi' => false,
                'viewGroups' => $full,
            ],
        ]);
    }

    public function edit(Request $request, Person $person, JurisdictionOptions $options): Response
    {
        $this->authorize('update', $person);

        return $this->form($this->actor($request), $options, $person, null);
    }

    public function update(PersonRequest $request, Person $person, SavePerson $save): RedirectResponse
    {
        $this->authorize('update', $person);

        $save->update($person, $request->community(), $request->values(), $request->roles(), $this->actor($request));

        return redirect()->route('persons.show', $person)
            ->with('toast', ['type' => 'success', 'message' => 'Person saved.']);
    }

    public function destroy(DeleteRecordRequest $request, Person $person, DeleteWithReason $delete): RedirectResponse
    {
        $this->authorize('delete', $person);

        $name = $person->name;
        $delete($person, 'person', (string) $request->validated('reason'), $this->actor($request));

        return redirect()->route('persons.index')
            ->with('toast', ['type' => 'success', 'message' => "{$name} deleted."]);
    }

    /**
     * @param  array{q: string, role: string, state: string, lga: string, gender: string, classification: string, dob: string}  $filters
     */
    private function searchPage(User $actor, JurisdictionOptions $options, PersonMatchService $matches, array $filters): Response
    {
        $states = $options->states($actor);
        $state = $filters['state'] !== '' ? $states->firstWhere('ulid', $filters['state']) : null;
        $lgas = $options->lgas($actor, $state);
        $lga = $filters['lga'] !== '' ? $lgas->firstWhere('ulid', $filters['lga']) : null;
        $term = SearchTerm::parse($filters['q']);
        $filtered = $state !== null || $lga !== null || $filters['role'] !== '' || $filters['gender'] !== '' || $filters['classification'] !== '';

        $query = Person::query()
            ->with(['lga', 'state', 'roles', 'media' => fn ($q) => $q->where('collection_name', Person::PHOTOS)])
            ->when($state, fn (Builder $q) => $q->where('persons.state_id', $state?->id))
            ->when($lga, fn (Builder $q) => $q->where('persons.lga_id', $lga?->id))
            ->when($filters['role'], fn (Builder $q, string $role) => $q->whereHas('roles', fn (Builder $r) => $r->where('role', $role)->where('is_active', true)))
            ->when($filters['gender'], fn (Builder $q, string $gender) => $q->where('persons.gender', $gender))
            ->when($filters['classification'], fn (Builder $q, string $level) => $q->where('persons.classification', $level));

        [$mode, $limit] = match (true) {
            $term->isEmpty() && ! $filtered => ['recent', self::RECENT_LIMIT],
            $term->isEmpty() => ['filtered', self::RESULT_LIMIT],
            $term->isNumber() => ['number', self::RESULT_LIMIT],
            default => ['name', self::RESULT_LIMIT],
        };

        /** @var Collection<int, Person> $results */
        $results = match ($mode) {
            'recent' => $query->orderByDesc('persons.updated_at')->limit($limit)->get(),
            'filtered' => $query->orderBy('persons.last_name')->orderBy('persons.first_name')->limit($limit + 1)->get(),
            'number' => PersonSearch::byNumber($query, $term)->limit($limit + 1)->get(),
            default => PersonSearch::withThresholds(PersonSearch::SIMILARITY, PersonSearch::WORD_SIMILARITY, fn () => PersonSearch::byName($query, $term->raw)->limit($limit + 1)->get()),
        };

        $cross = $actor->can('create', RecordAccessRequest::class)
            ? ($term->isEmpty() ? new MatchResult(new Collection, []) : $matches->match(MatchCriteria::fromSearchTerm($term, $filters['dob'] === '' ? null : $filters['dob']), $actor, MatchContext::PersonSearch))
            : null;

        Inertia::encryptHistory();

        return Inertia::render('persons/index', [
            'results' => $results->take($limit)->map(fn (Person $person) => PersonPresenter::row($person))->values(),
            'truncated' => $results->count() > $limit,
            'mode' => $mode,
            'kind' => $term->kind(),
            'filters' => $filters,
            'options' => PersonPresenter::options(),
            'states' => $states->count() > 1 ? $states->map(fn ($s) => ['ulid' => $s->ulid, 'name' => $s->name])->values() : [],
            'lgas' => $lgas->count() > 1 ? $lgas->map(fn (Lga $l) => ['ulid' => $l->ulid, 'name' => $l->name])->values() : [],
            // Records elsewhere (Phase 3C): null when the user never gets hits
            // (national users, and anyone who may not request access).
            'crossJurisdiction' => $cross === null ? null : [
                'hits' => $cross->hitsArray(),
                'limitReached' => $cross->limitReached,
                'options' => AccessRequestPresenter::options(),
            ],
            'can' => ['create' => $actor->can('create', Person::class)],
        ]);
    }

    private function form(User $actor, JurisdictionOptions $options, ?Person $person, mixed $lgaUlid): Response
    {
        $lgas = $person === null ? $options->lgas($actor)->load('state:id,ulid,name') : new Collection;
        $lga = $person !== null ? $person->lga : (is_string($lgaUlid) ? $lgas->firstWhere('ulid', $lgaUlid) : null) ?? ($lgas->count() === 1 ? $lgas->first() : null);

        if ($person !== null) {
            Inertia::encryptHistory();
        }

        return Inertia::render('persons/form', [
            'person' => $person === null ? null : PersonPresenter::detail($person),
            'lgas' => $lgas->map(fn (Lga $l) => ['ulid' => $l->ulid, 'name' => $l->name, 'state' => $l->state->name])->values(),
            'selectedLga' => $lga?->ulid,
            'communities' => $lga === null ? [] : Community::query()
                ->where('lga_id', $lga->id)
                ->orderBy('name')
                ->get(['ulid', 'name'])
                ->map(fn (Community $c) => ['value' => $c->ulid, 'label' => $c->name]),
            'options' => PersonPresenter::options(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function similarMatches(SimilarPersons $similar, array $data): array
    {
        $candidate = [
            'first_name' => (string) ($data['first_name'] ?? ''),
            'last_name' => (string) ($data['last_name'] ?? ''),
            'other_names' => isset($data['other_names']) ? (string) $data['other_names'] : null,
            'nin' => isset($data['nin']) ? (preg_replace('/\s+/', '', (string) $data['nin']) ?: null) : null,
            'phone' => isset($data['phone']) ? PhoneNumber::normalise((string) $data['phone']) : null,
            'date_of_birth' => isset($data['date_of_birth']) ? (string) $data['date_of_birth'] : null,
        ];

        if (trim($candidate['first_name'].$candidate['last_name']) === '') {
            return [];
        }

        return array_map(fn (array $match) => [
            ...PersonPresenter::row($match['person']),
            'reasons' => $match['reasons'],
        ], $similar->find($candidate));
    }

    /**
     * The person's incidents (Phase 5D), with their involvement in each.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function incidents(Person $person): LengthAwarePaginator
    {
        $page = IncidentLinks::rows(IncidentLinks::person($person));
        $involvement = IncidentLinks::involvements($person, array_column($page->items(), 'ulid'));

        /** @var LengthAwarePaginator<int, array<string, mixed>> */
        return $page->through(fn (array $row) => [...$row, 'involvement' => $involvement[$row['ulid']] ?? null]);
    }

    /**
     * Associations from both sides, only with persons the user can see.
     *
     * @return list<array<string, mixed>>
     */
    private function associations(Person $person): array
    {
        $links = PersonAssociation::query()
            ->where(fn ($q) => $q->where('person_id', $person->id)->orWhere('associated_person_id', $person->id))
            ->latest('id')
            ->get();

        $others = Person::query()
            ->with(['lga', 'roles'])
            ->whereIn('id', $links->map(fn (PersonAssociation $link) => $link->otherPersonId($person)))
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($links as $link) {
            $other = $others->get($link->otherPersonId($person));

            if (! $other instanceof Person) {
                continue;
            }

            $rows[] = [
                'ulid' => $link->ulid,
                'person' => PersonPresenter::row($other),
                'relationship' => PersonPresenter::option($link->relationshipFor($person)),
                'notes' => $link->notes,
                'added_at' => $link->created_at?->toIso8601String(),
            ];
        }

        return $rows;
    }

    /**
     * The person's audit history, including their roles and aliases.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function history(Person $person): LengthAwarePaginator
    {
        return self::historyOf($person);
    }

    /**
     * A person's audit history (also a merged record's, Phase 10A).
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public static function historyOf(Person $person): LengthAwarePaginator
    {
        return Activity::query()
            ->with(['causer', 'subject'])
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $s) => $s->where('subject_type', $person->getMorphClass())->where('subject_id', $person->id))
                ->orWhere(fn (Builder $s) => $s->where('subject_type', (new PersonRole)->getMorphClass())
                    ->whereIn('subject_id', PersonRole::query()->where('person_id', $person->id)->select('id')))
                ->orWhere(fn (Builder $s) => $s->where('subject_type', (new PersonAlias)->getMorphClass())
                    ->whereIn('subject_id', PersonAlias::withTrashed()->where('person_id', $person->id)->select('id'))))
            ->latest('id')
            ->paginate(25, pageName: 'history_page')
            ->withQueryString()
            ->through(fn (Activity $activity) => ActivityPresenter::present($activity));
    }

    /**
     * Current and past offices the user can see.
     *
     * @return array{current: list<array<string, mixed>>, past: list<array<string, mixed>>}
     */
    private function offices(Person $person): array
    {
        $offices = PoliticalOffice::query()
            ->where('person_id', $person->id)
            ->with(['state', 'senatorialDistrict', 'lga', 'ward'])
            ->orderByDesc('started_on')
            ->get();
        $user = request()->user();
        $present = fn (PoliticalOffice $o) => [
            ...PoliticalPresenter::office($o, withPerson: false),
            'can' => ['update' => $user?->can('update', $o) ?? false, 'delete' => $user?->can('delete', $o) ?? false],
        ];

        return [
            'current' => array_values($offices->filter(fn (PoliticalOffice $o) => $o->isCurrent())->map($present)->all()),
            'past' => array_values($offices->reject(fn (PoliticalOffice $o) => $o->isCurrent())->map($present)->all()),
        ];
    }

    /**
     * The person's POI record, when there is one the user may view. Reading
     * it is access-logged on the POI with context "poi".
     *
     * @return array<string, mixed>|null
     */
    private function poi(Person $person, User $actor): ?array
    {
        $poi = PersonOfInterest::query()->withCoordinates()->with(['lga', 'reviewer', 'person.lga', 'person.roles'])->where('person_id', $person->id)->first();

        if ($poi === null || ! $actor->can('view', $poi)) {
            return null;
        }

        $poi->recordAccess(context: 'poi');

        return [
            ...PoiPresenter::detail($poi),
            'can' => [
                'update' => $actor->can('update', $poi),
                'clear' => $actor->can('clear', $poi),
                'delete' => $actor->can('delete', $poi),
            ],
        ];
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
