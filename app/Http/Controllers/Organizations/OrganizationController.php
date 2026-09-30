<?php

namespace App\Http\Controllers\Organizations;

use App\Actions\Organizations\DeleteOrganization;
use App\Actions\Organizations\SaveOrganization;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ActivityPresenter;
use App\Http\Presenters\OrganizationPresenter;
use App\Http\Requests\LocalGovernment\DeleteRecordRequest;
use App\Http\Requests\Organizations\OrganizationRequest;
use App\Models\Organization;
use App\Models\OrganizationContact;
use App\Models\OrganizationSector;
use App\Models\OrganizationSite;
use App\Models\User;
use App\Support\Persons\PersonSearch;
use App\Support\Persons\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * The organization directory (Phase 4B). Every intelligence role sees every
 * directory entry; sites (and their counts) only within jurisdiction and
 * classification. The search box also looks up contacts by phone number or
 * email (exact, via blind indexes, within jurisdiction).
 */
class OrganizationController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Organization::class);
        $user = $this->actor($request);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::enum(OrganizationType::class)],
            'sector' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', Rule::enum(OrganizationStatus::class)],
            'in_my_area' => ['nullable', Rule::in(['1'])],
        ]);
        $term = trim((string) ($filters['q'] ?? ''));
        $lookup = self::lookupKind($term);
        $sector = isset($filters['sector']) ? OrganizationSector::query()->where('ulid', $filters['sector'])->first() : null;

        $query = Organization::query()
            ->with('sector')
            ->withCount('sites')
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('organizations.type', $type))
            ->when($sector, fn (Builder $q) => $q->where('organizations.organization_sector_id', $sector?->id))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('organizations.status', $status))
            ->when(isset($filters['in_my_area']), fn (Builder $q) => $q->whereHas('sites'));

        $organizations = $term !== '' && $lookup === null
            ? PersonSearch::withThresholds(PersonSearch::SIMILARITY, PersonSearch::WORD_SIMILARITY, fn () => $query->search($term)->paginate(25)->withQueryString())
            : $query->orderBy('organizations.name')->paginate(25)->withQueryString();

        return Inertia::render('organizations/index', [
            'organizations' => $organizations->through(fn (Organization $o) => OrganizationPresenter::row($o)),
            'contacts' => $lookup === null ? null : $this->lookup($user, $lookup, $term),
            'lookup' => $lookup,
            'filters' => [
                'q' => $term,
                'type' => $filters['type'] ?? '',
                'sector' => $filters['sector'] ?? '',
                'status' => $filters['status'] ?? '',
                'in_my_area' => isset($filters['in_my_area']) ? '1' : '',
            ],
            'options' => OrganizationPresenter::options(),
            'sectors' => OrganizationPresenter::sectorOptions(),
            'can' => ['create' => $user->can('create', Organization::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Organization::class);

        return $this->form(null);
    }

    /**
     * Possible duplicates of a new entry: fuzzy name or other names (the
     * trigram index: `%` and `<%` operators) and the exact RC number.
     */
    public function similar(Request $request): JsonResponse
    {
        $this->authorize('create', Organization::class);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'other_names' => ['nullable', 'array', 'max:20'],
            'other_names.*' => ['nullable', 'string', 'max:255'],
            'rc_number' => ['nullable', 'string', 'max:32'],
        ]);

        $names = array_values(array_filter([$data['name'] ?? null, ...($data['other_names'] ?? [])], fn (mixed $n) => is_string($n) && trim($n) !== ''));
        $rc = isset($data['rc_number']) ? OrganizationRequest::normaliseRc((string) $data['rc_number']) : null;

        $matches = PersonSearch::withThresholds(PersonSearch::DUPLICATE_SIMILARITY, PersonSearch::DUPLICATE_SIMILARITY, fn () => Organization::query()
            ->with('sector')
            ->withCount('sites')
            ->where(function (Builder $q) use ($names, $rc): void {
                foreach ($names as $name) {
                    // search_name also holds the other names, which dilutes
                    // plain similarity: word similarity catches the name
                    // within it. Both operators use the trigram index.
                    $q->orWhereRaw('organizations.search_name % buffalo_search_text(?)', [$name])
                        ->orWhereRaw('buffalo_search_text(?) <% organizations.search_name', [$name]);
                }

                if ($rc !== null) {
                    $q->orWhere('organizations.rc_number', $rc);
                }

                if ($names === [] && $rc === null) {
                    $q->whereRaw('1 = 0');
                }
            })
            ->limit(10)
            ->get());

        return response()->json([
            'matches' => $matches->map(fn (Organization $o) => [
                ...OrganizationPresenter::row($o),
                'same_rc_number' => $rc !== null && $o->rc_number === $rc,
            ])->values(),
        ]);
    }

    public function store(OrganizationRequest $request, SaveOrganization $save): RedirectResponse
    {
        $this->authorize('create', Organization::class);

        $organization = $save->create($request->values(), $this->actor($request));

        return redirect()->route('organizations.show', $organization)
            ->with('toast', ['type' => 'success', 'message' => "\"{$organization->name}\" added to the directory."]);
    }

    public function show(Request $request, Organization $organization): Response
    {
        $this->authorize('view', $organization);
        $user = $this->actor($request);
        $tab = $request->query('tab') === 'history' ? 'history' : 'overview';

        $organization->load('sector')->loadCount('sites');
        // Through the site scopes: only sites the viewer can see.
        $sites = OrganizationSite::query()->withCoordinates()->with(['organization', 'lga', 'state'])
            ->where('organization_sites.organization_id', $organization->id)
            ->orderBy('organization_sites.name')
            ->get();

        return Inertia::render('organizations/show', [
            'organization' => OrganizationPresenter::detail($organization),
            'sites' => $sites->map(fn (OrganizationSite $s) => OrganizationPresenter::site($s))->values(),
            'tab' => $tab,
            'history' => $tab === 'history'
                ? Activity::query()->with(['causer', 'subject'])
                    ->where('subject_type', $organization->getMorphClass())->where('subject_id', $organization->id)
                    ->latest('id')
                    ->paginate(25, pageName: 'history_page')
                    ->withQueryString()
                    ->through(fn (Activity $a) => ActivityPresenter::present($a))
                : null,
            'can' => [
                'update' => $user->can('update', $organization),
                'delete' => $user->can('delete', $organization),
                'addSite' => $user->can('create', OrganizationSite::class),
            ],
        ]);
    }

    public function edit(Organization $organization): Response
    {
        $this->authorize('update', $organization);

        return $this->form($organization->load('sector'));
    }

    public function update(OrganizationRequest $request, Organization $organization, SaveOrganization $save): RedirectResponse
    {
        $this->authorize('update', $organization);

        $save->update($organization, $request->values(), $this->actor($request));

        return redirect()->route('organizations.show', $organization)->with('toast', ['type' => 'success', 'message' => 'Organization saved.']);
    }

    public function destroy(DeleteRecordRequest $request, Organization $organization, DeleteOrganization $delete): RedirectResponse
    {
        $this->authorize('delete', $organization);

        $name = $organization->name;
        $delete($organization, (string) $request->validated('reason'), $this->actor($request));

        return redirect()->route('organizations.index')->with('toast', ['type' => 'success', 'message' => "\"{$name}\" deleted from the directory."]);
    }

    private function form(?Organization $organization): Response
    {
        return Inertia::render('organizations/form', [
            'organization' => $organization === null ? null : OrganizationPresenter::detail($organization->loadCount('sites')),
            'sectors' => OrganizationPresenter::sectorOptions($organization?->sector),
            'options' => OrganizationPresenter::options(),
        ]);
    }

    /** 'email', 'phone' or null (a name search). */
    private static function lookupKind(string $term): ?string
    {
        return match (true) {
            $term === '' => null,
            str_contains($term, '@') => 'email',
            preg_match('/^[\d\s+()\-.\/]{7,}$/', $term) === 1 && PhoneNumber::normalise($term) !== null => 'phone',
            default => null,
        };
    }

    /**
     * "Who does this number belong to?": contacts within jurisdiction whose
     * phone or email matches exactly (blind index). Each shown contact's
     * view is logged.
     *
     * @return list<array<string, mixed>>
     */
    private function lookup(User $user, string $kind, string $term): array
    {
        $value = $kind === 'phone' ? PhoneNumber::normalise($term) : mb_strtolower(trim($term));

        if ($value === null) {
            return [];
        }

        $contacts = OrganizationContact::query()
            ->with(['site.organization', 'site.lga', 'site.state', 'person'])
            ->whereBlind($kind, "{$kind}_bidx", $value)
            ->limit(50)
            ->get()
            ->filter(fn (OrganizationContact $c) => $user->can('view', $c));

        return array_values($contacts->map(function (OrganizationContact $contact) use ($user): array {
            $contact->recordAccess(context: 'lookup');

            return [
                ...OrganizationPresenter::contact($contact, $user),
                'site' => OrganizationPresenter::site($contact->site),
            ];
        })->all());
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
