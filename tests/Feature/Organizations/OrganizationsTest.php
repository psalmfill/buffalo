<?php

use App\Actions\Organizations\DeleteOrganization;
use App\Enums\Classification;
use App\Enums\SecurityRelevance;
use App\Enums\SiteType;
use App\Models\AgencyFormation;
use App\Models\Community;
use App\Models\Organization;
use App\Models\OrganizationSector;
use App\Models\OrganizationSite;
use App\Models\OrganizationSiteFormation;
use App\Models\RecordAccessLog;
use App\Models\SecurityAgency;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Persons\PersonSearch;
use App\Support\ReferenceData\Contracts\ReferenceUsage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(function () {
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->sector = OrganizationSector::factory()->create(['name' => 'Oil and gas', 'code' => 'oil_and_gas_test']);
});

function organizationPayload(array $overrides = []): array
{
    return [
        'name' => 'Niger Delta Petroleum Company',
        'other_names' => ['NDPC'],
        'type' => 'company',
        'sector' => test()->sector->ulid,
        'rc_number' => 'RC 123456',
        'status' => 'active',
        ...$overrides,
    ];
}

function sitePayload(array $overrides = []): array
{
    return [
        'lga' => test()->world->lgas['A1a']->ulid,
        'name' => 'Bonny Export Terminal',
        'site_type' => 'terminal',
        'security_relevance' => 'critical_infrastructure',
        'has_private_security' => false,
        'status' => 'active',
        'classification' => 'restricted',
        ...$overrides,
    ];
}

/*
|--------------------------------------------------------------------------
| The directory
|--------------------------------------------------------------------------
*/

test('the directory has no location columns', function () {
    $columns = Schema::getColumnListing('organizations');

    expect(array_intersect($columns, ['state_id', 'lga_id', 'community_id', 'location', 'ward_id', 'classification']))->toBe([]);
});

test('every intelligence role sees every directory entry; super admin is denied', function (string $who, bool $allowed) {
    $organization = Organization::factory()->create();
    OrganizationSite::factory()->of($organization)->in($this->world->lgas['B1'])->create();

    $response = CrossFixtures::signIn($this->world->users[$who])->get("/organizations/{$organization->ulid}");
    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with([
    ['director_general', true],
    ['ddg_zone_a', true],
    ['state_director', true],
    ['deputy_state_director', true],
    ['lga_chief', true],
    ['super_admin', false],
]);

test('an entry is created with a normalised RC number; the RC number is unique', function () {
    CrossFixtures::signIn($this->world->users['lga_chief'])->post('/organizations', organizationPayload())->assertRedirect();

    $organization = Organization::query()->sole();
    expect($organization->rc_number)->toBe('RC123456')
        ->and($organization->other_names)->toBe(['NDPC']);

    CrossFixtures::signIn($this->world->users['lga_chief'])->post('/organizations', organizationPayload(['name' => 'Other', 'rc_number' => 'rc-123456']))
        ->assertSessionHasErrors(['rc_number' => 'Another organization already has this RC number.']);
});

test('the duplicate check finds a similar name or other name, and the same RC number', function () {
    $existing = Organization::factory()->create(['name' => 'Niger Delta Petroleum Company', 'other_names' => ['NDPC Oil'], 'rc_number' => 'RC999']);
    Organization::factory()->create(['name' => 'Kano Textile Mills']);
    $chief = $this->world->users['lga_chief'];

    $match = fn (array $body) => collect(CrossFixtures::signIn($chief)->postJson('/organizations/similar', $body)->assertOk()->json('matches'));

    expect($match(['name' => 'Niger Delta Petrolem Co'])->pluck('ulid')->all())->toBe([$existing->ulid])
        ->and($match(['name' => 'Totally Different', 'other_names' => ['NDPC Oil']])->pluck('ulid')->all())->toBe([$existing->ulid])
        ->and($match(['name' => 'Unrelated', 'rc_number' => 'rc 999'])->first()['same_rc_number'])->toBeTrue()
        ->and($match(['name' => 'Unrelated Name'])->all())->toBe([]);
});

test('the name search uses the trigram index', function () {
    DB::statement(<<<'SQL'
        INSERT INTO organizations (ulid, name, type, organization_sector_id, status, created_at, updated_at)
        SELECT lpad(to_hex(g), 26, '0'), 'Company ' || md5(g::text), 'company', ?, 'active', now(), now()
        FROM generate_series(1, 20000) g
    SQL, [$this->sector->id]);
    DB::statement('ANALYZE organizations');

    $plan = PersonSearch::withThresholds(0.6, 1.0, fn () => collect(DB::select('EXPLAIN '.
        Organization::query()->whereRaw('organizations.search_name % buffalo_search_text(?)', ['Niger Delta Petroleum'])->toRawSql()))
        ->map(fn ($r) => implode(' ', (array) $r))->implode("\n"));

    expect($plan)->toContain('organizations_search_name_trgm');
});

test('an organization with sites cannot be deleted, and the message reveals nothing more', function () {
    $organization = Organization::factory()->create();
    // A secret site in another zone: invisible to the DDG of zone A.
    OrganizationSite::factory()->of($organization)->in($this->world->lgas['B1'])->classified(Classification::Secret)->create();
    $ddg = $this->world->users['ddg_zone_a'];

    CrossFixtures::signIn($ddg)->delete("/organizations/{$organization->ulid}", ['reason' => 'Duplicate entry'])
        ->assertSessionHasErrors(['reason' => DeleteOrganization::HAS_SITES]);
    expect($organization->fresh()->trashed())->toBeFalse();

    // Deletion rights: DG and DDG only.
    foreach (['state_director', 'deputy_state_director', 'lga_chief', 'super_admin'] as $who) {
        expect($this->world->users[$who]->can('delete', $organization))->toBeFalse();
    }

    $empty = Organization::factory()->create();
    CrossFixtures::signIn($ddg)->delete("/organizations/{$empty->ulid}", ['reason' => 'Duplicate entry'])->assertRedirect('/organizations');
    expect($empty->fresh()->trashed())->toBeTrue();
});

test('a sector in use is protected in reference data', function () {
    expect(app(ReferenceUsage::class)->isInUse($this->sector))->toBeFalse();

    Organization::factory()->create(['organization_sector_id' => $this->sector->id]);

    expect(app(ReferenceUsage::class)->isInUse($this->sector))->toBeTrue();
    CrossFixtures::signIn($this->world->users['super_admin'])->get('/reference-data/organization-sectors')
        ->assertInertia(fn (Assert $page) => $page->where('values', fn ($rows) => collect($rows)->firstWhere('ulid', $this->sector->ulid)['in_use'] === true));
});

/*
|--------------------------------------------------------------------------
| Sites
|--------------------------------------------------------------------------
*/

test('sites follow the jurisdiction matrix', function (string $who) {
    $organization = Organization::factory()->create();

    foreach (['own_lga' => 'A1a', 'same_state' => 'A1b', 'same_zone' => 'A2', 'other_zone' => 'B1'] as $label => $lga) {
        OrganizationSite::factory()->of($organization)->in($this->world->lgas[$lga])->create(['name' => $label]);
    }

    $expected = array_values(array_filter(JurisdictionWorld::EXPECTED[$who], fn (string $l) => $l !== 'state_level'));
    sort($expected);

    expect(app(JurisdictionContext::class)->actingAs($this->world->users[$who], fn () => OrganizationSite::query()->orderBy('name')->pluck('name')->all()))
        ->toBe($expected);
})->with(array_keys(JurisdictionWorld::EXPECTED));

test('sites are classified; confidential and secret views are logged', function () {
    $organization = Organization::factory()->create();

    foreach (Classification::cases() as $level) {
        OrganizationSite::factory()->of($organization)->in($this->world->lgas['A1a'])->classified($level)->create(['name' => $level->value]);
    }

    $visible = fn (string $who) => app(JurisdictionContext::class)->actingAs($this->world->users[$who], fn () => OrganizationSite::query()->orderBy('name')->pluck('name')->all());

    expect($visible('lga_chief'))->toBe(['restricted'])
        ->and($visible('deputy_state_director'))->toBe(['confidential', 'restricted'])
        ->and($visible('state_director'))->toBe(['confidential', 'restricted', 'secret']);

    $restricted = OrganizationSite::query()->where('name', 'restricted')->sole();
    $secret = OrganizationSite::query()->where('name', 'secret')->sole();
    CrossFixtures::signIn($this->world->users['state_director'])->get("/organization-sites/{$restricted->ulid}")->assertOk();
    CrossFixtures::signIn($this->world->users['state_director'])->get("/organization-sites/{$secret->ulid}")->assertOk();

    expect(RecordAccessLog::query()->where('subject_id', $restricted->id)->where('subject_type', $restricted->getMorphClass())->exists())->toBeFalse()
        ->and(RecordAccessLog::query()->where('subject_id', $secret->id)->where('subject_type', $secret->getMorphClass())->exists())->toBeTrue();
});

test('search results and the organization page count and list only visible sites', function () {
    $organization = Organization::factory()->create(['name' => 'Niger Delta Petroleum Company']);
    OrganizationSite::factory()->of($organization)->in($this->world->lgas['A1a'])->create(['name' => 'Mine']);
    OrganizationSite::factory()->of($organization)->in($this->world->lgas['A1b'])->create(['name' => 'Next LGA']);
    OrganizationSite::factory()->of($organization)->in($this->world->lgas['B1'])->create(['name' => 'Other state']);
    OrganizationSite::factory()->of($organization)->in($this->world->lgas['A1a'])->classified(Classification::Secret)->create(['name' => 'Secret']);

    CrossFixtures::signIn($this->world->users['lga_chief'])->get('/organizations?q=Niger')
        ->assertInertia(fn (Assert $page) => $page->where('organizations.data.0.visible_sites', 1));
    CrossFixtures::signIn($this->world->users['state_director'])->get('/organizations')
        ->assertInertia(fn (Assert $page) => $page->where('organizations.data.0.visible_sites', 3));

    CrossFixtures::signIn($this->world->users['lga_chief'])->get("/organizations/{$organization->ulid}")
        ->assertInertia(fn (Assert $page) => $page->has('sites', 1)->where('sites.0.name', 'Mine')->where('organization.visible_sites', 1))
        ->assertDontSee('Other state');

    // "Has sites in my area"
    Organization::factory()->create(['name' => 'Elsewhere Only']);
    CrossFixtures::signIn($this->world->users['lga_chief'])->get('/organizations?in_my_area=1')
        ->assertInertia(fn (Assert $page) => $page->has('organizations.data', 1));
});

test('a site is recorded within jurisdiction; one head office per organization', function () {
    $organization = Organization::factory()->create();
    $chief = $this->world->users['lga_chief'];
    $community = Community::factory()->in($this->world->lgas['A1a'])->create();

    CrossFixtures::signIn($chief)->post("/organizations/{$organization->ulid}/sites", sitePayload(['lga' => $this->world->lgas['A1b']->ulid]))
        ->assertSessionHasErrors('lga');
    CrossFixtures::signIn($chief)->post("/organizations/{$organization->ulid}/sites", sitePayload(['community' => $community->ulid, 'latitude' => 6.4, 'longitude' => 3.4]))
        ->assertRedirect();
    expect(Activity::query()->where('event', 'location_changed')->exists())->toBeTrue();

    // A head office elsewhere (not visible to this chief) still blocks a second one.
    OrganizationSite::factory()->of($organization)->in($this->world->lgas['B1'])->create(['site_type' => SiteType::HeadOffice]);
    CrossFixtures::signIn($chief)->post("/organizations/{$organization->ulid}/sites", sitePayload(['name' => 'HQ', 'site_type' => 'head_office']))
        ->assertSessionHasErrors(['site_type' => 'This organization already has a head office.']);
    expect(fn () => DB::transaction(fn () => OrganizationSite::factory()->of($organization)->in($this->world->lgas['A1a'])->create(['site_type' => SiteType::HeadOffice])))
        ->toThrow(QueryException::class, 'organization_sites_one_head_office');
});

test('the security provider must be another directory entry; non private-security providers are allowed with a warning', function () {
    $organization = Organization::factory()->create();
    $bank = Organization::factory()->create();
    $security = Organization::factory()->create(['organization_sector_id' => OrganizationSector::factory()->create(['code' => 'private_security'])->id]);

    CrossFixtures::signIn($this->world->users['lga_chief'])->post("/organizations/{$organization->ulid}/sites", sitePayload(['security_provider' => $organization->ulid]))
        ->assertSessionHasErrors('security_provider');
    CrossFixtures::signIn($this->world->users['lga_chief'])->post("/organizations/{$organization->ulid}/sites", sitePayload(['security_provider' => $bank->ulid]))
        ->assertSessionHasNoErrors();

    $site = OrganizationSite::query()->sole();
    CrossFixtures::signIn($this->world->users['lga_chief'])->get("/organization-sites/{$site->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('site.security_provider.is_private_security', false));

    $site->forceFill(['security_provider_organization_id' => $security->id])->save();
    CrossFixtures::signIn($this->world->users['lga_chief'])->get("/organization-sites/{$site->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('site.security_provider.is_private_security', true));
});

test('linking a formation needs both the site and the formation to be visible', function () {
    $site = OrganizationSite::factory()->in($this->world->lgas['A1a'])->create();
    $navy = SecurityAgency::factory()->create();
    $mine = AgencyFormation::factory()->of($navy)->in($this->world->lgas['A1a'])->create();
    $hidden = AgencyFormation::factory()->of($navy)->in($this->world->lgas['A1b'])->create();
    $chief = $this->world->users['lga_chief'];

    CrossFixtures::signIn($chief)->post("/organization-sites/{$site->ulid}/formations", ['formation' => $hidden->ulid, 'arrangement' => 'regular_patrol'])
        ->assertSessionHasErrors(['formation' => 'Choose a formation you can view.']);
    CrossFixtures::signIn($chief)->post("/organization-sites/{$site->ulid}/formations", ['formation' => $mine->ulid, 'arrangement' => 'stationed_on_site'])
        ->assertSessionHasNoErrors();

    // A site the user cannot see: 404.
    $other = OrganizationSite::factory()->in($this->world->lgas['B1'])->create();
    CrossFixtures::signIn($chief)->post("/organization-sites/{$other->ulid}/formations", ['formation' => $mine->ulid, 'arrangement' => 'on_call'])
        ->assertNotFound();

    // A link to a formation the viewer cannot see is not shown.
    $link = new OrganizationSiteFormation;
    $link->forceFill(['organization_site_id' => $site->id, 'agency_formation_id' => $hidden->id, 'arrangement' => 'on_call'])->save();
    CrossFixtures::signIn($chief)->get("/organization-sites/{$site->ulid}")
        ->assertInertia(fn (Assert $page) => $page->has('formations', 1)->where('formations.0.formation.ulid', $mine->ulid));
});

test('only DG, DDG and State Directors delete sites', function () {
    $site = OrganizationSite::factory()->in($this->world->lgas['A1a'])->create();

    foreach (['lga_chief', 'deputy_state_director', 'super_admin'] as $who) {
        expect($this->world->users[$who]->can('delete', $site))->toBeFalse();
    }

    CrossFixtures::signIn($this->world->users['state_director'])->delete("/organization-sites/{$site->ulid}", ['reason' => 'Closed down'])->assertRedirect();
    expect($site->fresh()->trashed())->toBeTrue();
});

test('the LGA Organizations tab lists visible sites, filterable by relevance', function () {
    $organization = Organization::factory()->create();
    OrganizationSite::factory()->of($organization)->in($this->world->lgas['A1a'])->create(['name' => 'Terminal', 'security_relevance' => SecurityRelevance::CriticalInfrastructure]);
    OrganizationSite::factory()->of($organization)->in($this->world->lgas['A1a'])->create(['name' => 'Shop', 'security_relevance' => SecurityRelevance::Low]);
    OrganizationSite::factory()->of($organization)->in($this->world->lgas['A1a'])->classified(Classification::Secret)->create(['name' => 'Hidden']);
    $url = "/lgas/{$this->world->lgas['A1a']->ulid}?tab=organizations";

    CrossFixtures::signIn($this->world->users['lga_chief'])->get($url)
        ->assertInertia(fn (Assert $page) => $page->has('organizations.sites', 2)->where('organizations.sites.0.name', 'Terminal'));
    CrossFixtures::signIn($this->world->users['lga_chief'])->get("{$url}&relevance=low")
        ->assertInertia(fn (Assert $page) => $page->has('organizations.sites', 1)->where('organizations.sites.0.name', 'Shop'));
});

/*
|--------------------------------------------------------------------------
| Dashboards
|--------------------------------------------------------------------------
*/

test('dashboards show organization sites within jurisdiction and classification', function () {
    $organization = Organization::factory()->create(['organization_sector_id' => $this->sector->id]);
    $terminal = OrganizationSite::factory()->of($organization)->in($this->world->lgas['A1a'])->create(['name' => 'Terminal', 'security_relevance' => SecurityRelevance::CriticalInfrastructure]);
    OrganizationSite::factory()->of($organization)->in($this->world->lgas['A1b'])->create(['name' => 'Depot', 'security_relevance' => SecurityRelevance::High]);
    OrganizationSite::factory()->of($organization)->in($this->world->lgas['A1a'])->classified(Classification::Secret)->create(['security_relevance' => SecurityRelevance::CriticalInfrastructure]);
    OrganizationSite::factory()->of($organization)->in($this->world->lgas['B1'])->create(['security_relevance' => SecurityRelevance::High]);
    $link = new OrganizationSiteFormation;
    $link->forceFill(['organization_site_id' => $terminal->id, 'agency_formation_id' => AgencyFormation::factory()->in($this->world->lgas['A1a'])->create()->id, 'arrangement' => 'stationed_on_site'])->save();

    CrossFixtures::signIn($this->world->users['lga_chief'])->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('organizations.critical_infrastructure', 1)->where('organizations.high', 0));

    CrossFixtures::signIn($this->world->users['deputy_state_director'])->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->where('organizations.importantByLga', fn ($groups) => collect($groups)->flatMap(fn ($g) => collect($g['sites'])->pluck('name'))->sort()->values()->all() === ['Depot', 'Terminal'])
            ->where('organizations.withoutGovernmentSecurity', fn ($sites) => collect($sites)->pluck('name')->all() === ['Depot']));

    CrossFixtures::signIn($this->world->users['director_general'])->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('organizations', fn ($rows) => collect($rows)->sum('critical') === 2 && collect($rows)->sum('high') === 2));
    CrossFixtures::signIn($this->world->users['ddg_zone_b'])->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('organizations', fn ($rows) => collect($rows)->sum('critical') === 0 && collect($rows)->sum('high') === 1));
});
