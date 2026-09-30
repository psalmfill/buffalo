<?php

namespace App\Http\Controllers\Organizations;

use App\Actions\CommunityInformation\DeleteWithReason;
use App\Actions\Organizations\SaveOrganizationSite;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ActivityPresenter;
use App\Http\Presenters\OrganizationPresenter;
use App\Http\Requests\LocalGovernment\DeleteRecordRequest;
use App\Http\Requests\Organizations\OrganizationSiteRequest;
use App\Models\AgencyFormation;
use App\Models\Community;
use App\Models\Lga;
use App\Models\Organization;
use App\Models\OrganizationContact;
use App\Models\OrganizationSite;
use App\Models\OrganizationSiteFormation;
use App\Models\User;
use App\Support\Dashboard\MapView;
use App\Support\Jurisdiction\JurisdictionOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Organization sites (Phase 4B). {site} is bound through the jurisdiction
 * and classification scopes. Views of confidential and secret sites are
 * logged; every contact shown on the page is logged.
 */
class OrganizationSiteController extends Controller
{
    public function create(Request $request, Organization $organization, JurisdictionOptions $options): Response
    {
        $this->authorize('create', OrganizationSite::class);

        return $this->form($this->actor($request), $options, $organization, null, $request->query('lga'));
    }

    public function store(OrganizationSiteRequest $request, Organization $organization, SaveOrganizationSite $save): RedirectResponse
    {
        /** @var Lga $lga */
        $lga = $request->lga();
        $this->authorize('createIn', [OrganizationSite::class, $lga]);

        $site = $save->create($organization, $lga, $request->values(), $this->actor($request));

        return redirect()->route('organization-sites.show', $site)
            ->with('toast', ['type' => 'success', 'message' => "Site \"{$site->name}\" added."]);
    }

    public function show(Request $request, OrganizationSite $site): Response
    {
        $this->authorize('view', $site);
        $user = $this->actor($request);
        $tab = $request->query('tab') === 'history' ? 'history' : 'overview';

        if ($site->logsViews()) {
            $site->recordAccess(context: "tab:{$tab}");
        }
        Inertia::encryptHistory();

        $row = OrganizationSite::query()->withCoordinates()->whereKey($site->id)->firstOrFail();
        $row->load(['organization.sector', 'lga', 'state', 'community', 'securityProvider.sector']);

        // Contacts through their own scopes; each one shown is logged.
        $contacts = $tab === 'overview'
            ? OrganizationContact::query()->with('person')
                ->where('organization_site_id', $site->id)
                ->orderByDesc('is_primary')->orderByDesc('is_management')->orderByDesc('is_security_contact')->orderBy('name')
                ->get()
                ->filter(fn (OrganizationContact $c) => $user->can('view', $c))
            : collect();
        $contacts->each(fn (OrganizationContact $c) => $c->recordAccess(context: 'site_page'));

        $links = OrganizationSiteFormation::query()
            ->with(['formation' => fn ($q) => $q->withCoordinates()->with(['agency', 'lga', 'state'])])
            ->where('organization_site_id', $site->id)
            ->get();

        return Inertia::render('organization-sites/show', [
            'site' => OrganizationPresenter::siteDetail($row),
            'contacts' => $contacts->map(fn (OrganizationContact $c) => OrganizationPresenter::contact($c, $user))->values(),
            'formations' => $links->map(fn (OrganizationSiteFormation $l) => OrganizationPresenter::formationLink($l))->values(),
            'formationChoices' => $user->can('update', $site) ? $this->formationChoices($row, $links) : [],
            'tab' => $tab,
            'history' => $tab === 'history'
                ? Activity::query()->with(['causer', 'subject'])
                    ->where('subject_type', $site->getMorphClass())->where('subject_id', $site->id)
                    ->latest('id')
                    ->paginate(25, pageName: 'history_page')
                    ->withQueryString()
                    ->through(fn (Activity $a) => ActivityPresenter::present($a))
                : null,
            'options' => OrganizationPresenter::options(),
            'map' => MapView::for($row->lga),
            'can' => [
                'update' => $user->can('update', $site),
                'delete' => $user->can('delete', $site),
            ],
        ]);
    }

    public function edit(Request $request, OrganizationSite $site, JurisdictionOptions $options): Response
    {
        $this->authorize('update', $site);

        return $this->form($this->actor($request), $options, $site->organization, $site, null);
    }

    public function update(OrganizationSiteRequest $request, OrganizationSite $site, SaveOrganizationSite $save): RedirectResponse
    {
        $this->authorize('update', $site);

        $save->update($site, $request->values(), $this->actor($request));

        return redirect()->route('organization-sites.show', $site)->with('toast', ['type' => 'success', 'message' => 'Site saved.']);
    }

    public function destroy(DeleteRecordRequest $request, OrganizationSite $site, DeleteWithReason $delete): RedirectResponse
    {
        $this->authorize('delete', $site);

        $organization = $site->organization;
        $name = $site->name;
        $delete($site, 'organization_site', (string) $request->validated('reason'), $this->actor($request));

        return redirect()->route('organizations.show', $organization)->with('toast', ['type' => 'success', 'message' => "Site \"{$name}\" deleted."]);
    }

    /**
     * Formations the user can see in the site's state, not yet linked.
     *
     * @param  Collection<int, OrganizationSiteFormation>  $links
     * @return list<array{value: string, label: string}>
     */
    private function formationChoices(OrganizationSite $site, Collection $links): array
    {
        return array_values(AgencyFormation::query()->with(['agency', 'lga'])
            ->where('agency_formations.state_id', $site->state_id)
            ->whereNotIn('agency_formations.id', $links->pluck('agency_formation_id'))
            ->orderBy('agency_formations.name')
            ->limit(1000)
            ->get()
            ->map(fn (AgencyFormation $f) => ['value' => $f->ulid, 'label' => "{$f->name} ({$f->agency->short_name}, {$f->lga->name})"])
            ->all());
    }

    private function form(User $user, JurisdictionOptions $options, Organization $organization, ?OrganizationSite $site, mixed $lgaUlid): Response
    {
        $row = $site === null ? null : OrganizationSite::query()->withCoordinates()->whereKey($site->id)->firstOrFail();
        $row?->load(['organization', 'lga', 'state', 'community', 'securityProvider.sector']);
        $lgas = $site === null ? $options->lgas($user)->load('state:id,ulid,name') : collect();
        $lga = $row !== null ? $row->lga : (is_string($lgaUlid) ? $lgas->firstWhere('ulid', $lgaUlid) : ($lgas->count() === 1 ? $lgas->first() : null));

        return Inertia::render('organization-sites/form', [
            'organization' => ['ulid' => $organization->ulid, 'name' => $organization->name],
            'site' => $row === null ? null : OrganizationPresenter::siteDetail($row),
            'lga' => $lga === null ? null : ['ulid' => $lga->ulid, 'name' => $lga->name],
            'lgas' => $lgas->map(fn (Lga $l) => ['value' => $l->ulid, 'label' => "{$l->name} ({$l->state->name})"])->values(),
            'communities' => $lga === null ? [] : Community::query()->where('lga_id', $lga->id)->orderBy('name')->get(['ulid', 'name'])
                ->map(fn (Community $c) => ['value' => $c->ulid, 'label' => $c->name])->values(),
            'providers' => Organization::query()->with('sector')->whereKeyNot($organization->id)->orderBy('name')->limit(2000)->get()
                ->map(fn (Organization $o) => ['value' => $o->ulid, 'label' => $o->name, 'private_security' => $o->sector->code === 'private_security'])->values(),
            'options' => OrganizationPresenter::options(),
            'map' => MapView::for($lga),
        ]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
