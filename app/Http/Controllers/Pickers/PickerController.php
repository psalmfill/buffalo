<?php

namespace App\Http\Controllers\Pickers;

use App\Enums\IncidentStatus;
use App\Http\Controllers\Controller;
use App\Models\AgencyFormation;
use App\Models\Community;
use App\Models\CriminalGroup;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\Organization;
use App\Models\OrganizationSector;
use App\Models\OrganizationSite;
use App\Models\Person;
use App\Models\SecurityAgency;
use App\Models\State;
use App\Models\User;
use App\Support\Persons\PersonSearch;
use App\Support\Persons\SearchTerm;
use App\Support\Search\Trigram;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Searchable pickers (Phase 5A): one JSON endpoint per entity for the
 * EntityPicker component. Every query goes through the entity's
 * jurisdiction and classification scopes and is authorised by its policy
 * (`view` per record; `link` or `linkToIncident` for persons). At most 20
 * results: ULID, label and a short secondary line. POSTed, so search terms
 * stay out of URLs and logs.
 */
class PickerController extends Controller
{
    public const LIMIT = 20;

    public function persons(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Person::class);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'purpose' => ['nullable', Rule::in(['link', 'incident', 'arrest'])],
            'except' => ['nullable', 'string', 'size:26'],
        ]);
        $term = SearchTerm::parse((string) ($data['q'] ?? ''));

        if ($term->isEmpty() || mb_strlen($term->raw) < 2) {
            return $this->respond(collect());
        }

        $ability = match ($data['purpose'] ?? 'link') {
            'incident' => 'linkToIncident',
            'arrest' => 'linkToArrest',
            default => 'link',
        };
        $query = Person::query()->with(['lga', 'state'])
            ->when($data['except'] ?? null, fn (Builder $q, string $ulid) => $q->where('persons.ulid', '<>', $ulid));
        $results = $term->isNumber()
            ? PersonSearch::byNumber($query, $term)->limit(self::LIMIT)->get()
            : PersonSearch::withThresholds(PersonSearch::SIMILARITY, PersonSearch::WORD_SIMILARITY, fn () => PersonSearch::byName($query, $term->raw)->limit(self::LIMIT)->get());

        $user = $this->actor($request);

        return $this->respond($results
            ->filter(fn (Person $p) => $user->can($ability, $p))
            ->map(fn (Person $p) => self::row($p->ulid, $p->name, "{$p->lga->name} LGA, {$p->state->name}")));
    }

    public function communities(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Community::class);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'lga' => ['nullable', 'string', 'size:26']]);
        $lga = self::lga($data['lga'] ?? null);

        $results = Trigram::withWordSimilarity(Community::SEARCH_SIMILARITY, fn () => Community::query()->with(['lga', 'state'])
            ->when($lga, fn (Builder $q) => $q->where('communities.lga_id', $lga?->id))
            ->when(trim((string) ($data['q'] ?? '')) !== '', fn (Builder $q) => $q->search((string) $data['q']), fn (Builder $q) => $q->orderBy('communities.name'))
            ->limit(self::LIMIT)->get());

        return $this->respond($results->map(fn (Community $c) => self::row($c->ulid, $c->name, "{$c->lga->name} LGA, {$c->state->name}")));
    }

    public function formations(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AgencyFormation::class);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'agency' => ['nullable', 'string', 'size:26'],
            'state' => ['nullable', 'string', 'size:26'],
            'lga' => ['nullable', 'string', 'size:26'],
            'except' => ['nullable', 'string', 'size:26'],
        ]);
        $agency = isset($data['agency']) ? SecurityAgency::query()->where('ulid', $data['agency'])->first() : null;
        $state = isset($data['state']) ? State::query()->where('ulid', $data['state'])->first() : null;
        $lga = self::lga($data['lga'] ?? null);

        $results = AgencyFormation::query()->with(['agency', 'lga', 'state'])
            ->when(isset($data['agency']), fn (Builder $q) => $q->where('agency_formations.security_agency_id', ($agency === null ? 0 : $agency->id)))
            ->when(isset($data['state']), fn (Builder $q) => $q->where('agency_formations.state_id', ($state === null ? 0 : $state->id)))
            ->when(isset($data['lga']), fn (Builder $q) => $q->where('agency_formations.lga_id', ($lga === null ? 0 : $lga->id)))
            ->when($data['except'] ?? null, fn (Builder $q, string $ulid) => $q->where('agency_formations.ulid', '<>', $ulid))
            ->when(trim((string) ($data['q'] ?? '')) !== '', fn (Builder $q) => $q->search((string) $data['q']), fn (Builder $q) => $q->orderBy('agency_formations.name'))
            ->limit(self::LIMIT)->get();

        return $this->respond($results->map(fn (AgencyFormation $f) => self::row($f->ulid, $f->name, "{$f->agency->short_name} · {$f->formation_type->label()} · {$f->lga->name} LGA, {$f->state->name}")));
    }

    public function sites(Request $request): JsonResponse
    {
        $this->authorize('viewAny', OrganizationSite::class);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'organization' => ['nullable', 'string', 'size:26'],
            'lga' => ['nullable', 'string', 'size:26'],
        ]);
        $lga = self::lga($data['lga'] ?? null);
        $term = trim((string) ($data['q'] ?? ''));

        $results = OrganizationSite::query()->with(['organization', 'lga', 'state'])
            ->when(isset($data['organization']), fn (Builder $q) => $q->whereHas('organization', fn (Builder $o) => $o->where('ulid', $data['organization'])))
            ->when(isset($data['lga']), fn (Builder $q) => $q->where('organization_sites.lga_id', ($lga === null ? 0 : $lga->id)))
            // Sites have no search_name: match the site or organization name.
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('organization_sites.name', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhereHas('organization', fn (Builder $o) => $o->where('name', 'ilike', '%'.addcslashes($term, '%_\\').'%'))))
            ->orderBy('organization_sites.name')
            ->limit(self::LIMIT)->get();

        return $this->respond($results->map(fn (OrganizationSite $s) => self::row($s->ulid, $s->name, "{$s->organization->name} · {$s->lga->name} LGA, {$s->state->name}")));
    }

    /**
     * Submitted incidents the user can see (Phase 5C, e.g. the original of a
     * duplicate): by reference (exact, case-insensitive) or title; never
     * invalid ones or drafts.
     */
    public function incidents(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Incident::class);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'except' => ['nullable', 'string', 'max:30']]);
        $term = trim((string) ($data['q'] ?? ''));

        $results = Incident::query()->with(['type', 'lga'])
            ->whereNotNull('reference_no')
            ->where('status', '!=', IncidentStatus::Invalid->value)
            ->when(isset($data['except']), fn (Builder $q) => $q->where('ulid', '!=', $data['except']))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereRaw('lower(reference_no) = lower(?)', [$term])
                ->orWhere('title', 'ilike', '%'.addcslashes($term, '%_\\').'%')))
            ->latest('submitted_at')
            ->limit(self::LIMIT)->get();

        return $this->respond($results->map(fn (Incident $i) => self::row($i->ulid, (string) $i->reference_no, "{$i->title} · {$i->type->name}, {$i->lga->name} LGA")));
    }

    public function groups(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CriminalGroup::class);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $results = Trigram::withWordSimilarity(CriminalGroup::SEARCH_SIMILARITY, fn () => CriminalGroup::query()->with(['lga', 'state'])
            ->when(trim((string) ($data['q'] ?? '')) !== '', fn (Builder $q) => $q->search((string) $data['q']), fn (Builder $q) => $q->orderBy('criminal_groups.name'))
            ->limit(self::LIMIT)->get());

        return $this->respond($results->map(fn (CriminalGroup $g) => self::row($g->ulid, $g->name, "{$g->type->label()} · {$g->lga->name} LGA, {$g->state->name}")));
    }

    /** Flashpoints and blackspots the user can see (Phase 6B threat links). */
    public function flashpoints(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Flashpoint::class);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        // Own records only: a flashpoint shared with the user is never a link target (Phase 7A).
        $results = Flashpoint::query()->ownJurisdiction($this->actor($request))->with(['lga', 'state', 'threatType'])
            ->when(trim((string) ($data['q'] ?? '')) !== '', fn (Builder $q) => $q->search((string) $data['q']), fn (Builder $q) => $q->byConcern())
            ->limit(self::LIMIT)->get();

        return $this->respond($results->map(fn (Flashpoint $f) => self::row($f->ulid, $f->name, "{$f->kind->label()} · {$f->threatType->name} · {$f->lga->name} LGA, {$f->state->name}")));
    }

    public function organizations(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Organization::class);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'sector' => ['nullable', 'string', 'max:64'],
            'except' => ['nullable', 'string', 'size:26'],
        ]);
        $term = trim((string) ($data['q'] ?? ''));
        $sector = isset($data['sector']) ? OrganizationSector::query()->where('code', $data['sector'])->first() : null;

        $query = Organization::query()->with('sector')
            ->when(isset($data['sector']), fn (Builder $q) => $q->where('organizations.organization_sector_id', ($sector === null ? 0 : $sector->id)))
            ->when($data['except'] ?? null, fn (Builder $q, string $ulid) => $q->where('organizations.ulid', '<>', $ulid));

        $results = $term === ''
            ? $query->orderBy('organizations.name')->limit(self::LIMIT)->get()
            : PersonSearch::withThresholds(PersonSearch::SIMILARITY, PersonSearch::WORD_SIMILARITY, fn () => $query->search($term)->limit(self::LIMIT)->get());

        return $this->respond($results->map(fn (Organization $o) => [
            ...self::row($o->ulid, $o->name, "{$o->sector->name} · {$o->type->label()}"),
            'meta' => ['private_security' => $o->sector->code === 'private_security'],
        ]));
    }

    public function users(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term = trim((string) ($data['q'] ?? ''));

        $results = User::query()->with(['state', 'lga', 'roles'])
            ->where('is_active', true)
            ->when($term !== '', fn (Builder $q) => $q->where('users.name', 'ilike', '%'.addcslashes($term, '%_\\').'%'))
            ->orderBy('users.name')
            ->limit(self::LIMIT)->get();

        return $this->respond($results->map(fn (User $u) => self::row($u->ulid, $u->name, trim(($u->getRole()?->label() ?? '').' · '.$u->jurisdictionLabel(), ' ·'))));
    }

    /**
     * @template TRow of array<string, mixed>
     *
     * @param  Collection<array-key, TRow>  $rows
     */
    private function respond(Collection $rows): JsonResponse
    {
        return response()->json(['results' => $rows->take(self::LIMIT)->values()])->header('Cache-Control', 'private, no-store');
    }

    /**
     * @return array{value: string, label: string, secondary: string|null}
     */
    private static function row(string $ulid, string $label, ?string $secondary): array
    {
        return ['value' => $ulid, 'label' => $label, 'secondary' => $secondary];
    }

    private static function lga(mixed $ulid): ?Lga
    {
        return is_string($ulid) ? Lga::query()->where('ulid', $ulid)->first() : null;
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
