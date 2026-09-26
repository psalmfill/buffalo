<?php

use App\Models\Community;
use App\Models\Lga;
use App\Models\LgaProfile;
use App\Support\LocalGovernment\ProfileCompleteness;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Jurisdiction\JurisdictionWorld;

test('the LGA list shows only LGAs in the jurisdiction', function (string $who, array $visible) {
    $world = JurisdictionWorld::build();

    $this->actingAs($world->users[$who])->get('/lgas')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('lgas/index')
            ->where('lgas.data', fn ($rows) => collect($rows)->pluck('ulid')->sort()->values()->all()
                === collect($visible)->map(fn (string $key) => $world->lgas[$key]->ulid)->sort()->values()->all()));
})->with([
    ['director_general', ['A1a', 'A1b', 'A2', 'B1']],
    ['ddg_zone_a', ['A1a', 'A1b', 'A2']],
    ['state_director', ['A1a', 'A1b']],
    ['deputy_state_director', ['A1a', 'A1b']],
]);

test('an LGA chief goes straight to their LGA', function () {
    $world = JurisdictionWorld::build();

    $this->actingAs($world->users['lga_chief'])->get('/lgas')->assertRedirect('/lgas/'.$world->lgas['A1a']->ulid);
});

test('the super admin and users without a role have no LGA pages', function (string $who) {
    $world = JurisdictionWorld::build();

    $this->actingAs($world->users[$who])->get('/lgas')->assertForbidden();
    $this->actingAs($world->users[$who])->get('/lgas/'.$world->lgas['A1a']->ulid)->assertNotFound();
})->with(['super_admin', 'no_role']);

test('an LGA outside the jurisdiction is 404, for every LGA route', function () {
    $world = JurisdictionWorld::build();
    $other = $world->lgas['B1']->ulid;
    $this->actingAs($world->users['state_director']);

    $this->get("/lgas/{$other}")->assertNotFound();
    $this->get("/lgas/{$other}?tab=communities")->assertNotFound();
    $this->put("/lgas/{$other}/profile", ['terrain' => []])->assertNotFound();
    $this->post("/lgas/{$other}/review")->assertNotFound();
    $this->get("/lgas/{$other}/communities/create")->assertNotFound();
    $this->post("/lgas/{$other}/communities", ['name' => 'X', 'type' => 'village', 'other_names' => []])->assertNotFound();

    $this->flushSession();
    $this->actingAs($world->users['lga_chief'])->get('/lgas/'.$world->lgas['A1b']->ulid)->assertNotFound();
});

test('the LGA page has overview, profile and communities tabs', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $this->actingAs($world->users['state_director']);

    $this->get("/lgas/{$lga->ulid}")->assertInertia(fn (Assert $page) => $page
        ->component('lgas/show')
        ->where('tab', 'overview')
        ->where('tabs', ['overview', 'profile', 'communities', 'religious_institutions', 'vulnerable_population', 'security_notes'])
        ->where('completeness.percent', 0)
        ->has('overview.chief')
        ->where('profile', null)
        ->where('can.updateProfile', true)
        ->where('can.importCommunities', true));

    $this->get("/lgas/{$lga->ulid}?tab=profile")->assertInertia(fn (Assert $page) => $page->where('profile.exists', false)->has('profile.terrainOptions', 8));
    $this->get("/lgas/{$lga->ulid}?tab=communities")->assertInertia(fn (Assert $page) => $page->has('communities.list')->has('communities.markers'));
});

test('saving the profile creates it, updates the LGA basics and is audited', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $chief = $world->users['lga_chief'];

    $this->actingAs($chief)->put("/lgas/{$lga->ulid}/profile", [
        'history' => 'Founded long ago.',
        'economic_activities' => 'Farming, trade',
        'terrain' => ['riverine', 'rural', 'rural'],
        'major_roads' => '',
        'borders' => 'Borders A1b',
        'headquarters' => 'Town Hall',
        'area_km2' => '123.5',
        'population_estimate' => '45000',
        'code' => 'HACK',
        'name' => 'Renamed',
    ])->assertSessionHasErrors('terrain.2');

    $this->actingAs($chief)->put("/lgas/{$lga->ulid}/profile", [
        'history' => 'Founded long ago.',
        'economic_activities' => 'Farming, trade',
        'terrain' => ['riverine', 'rural'],
        'major_roads' => '',
        'borders' => 'Borders A1b',
        'headquarters' => 'Town Hall',
        'area_km2' => '123.5',
        'population_estimate' => '45000',
        'code' => 'HACK',
        'name' => 'Renamed',
    ])->assertSessionHasNoErrors();

    $profile = LgaProfile::query()->where('lga_id', $lga->id)->sole();
    $lga->refresh();

    expect($profile->state_id)->toBe($lga->state_id)
        ->and($profile->terrain)->toBe(['rural', 'riverine'])
        ->and($profile->created_by)->toBe($chief->id)
        ->and($lga->headquarters)->toBe('Town Hall')
        ->and($lga->population_estimate)->toBe(45000)
        // Names and codes stay admin/import-only.
        ->and($lga->name)->not->toBe('Renamed')
        ->and($lga->code)->not->toBe('HACK');

    expect(Activity::query()->where('subject_type', LgaProfile::class)->where('subject_id', $profile->id)->where('event', 'created')->exists())->toBeTrue()
        ->and(Activity::query()->where('subject_type', Lga::class)->where('subject_id', $lga->id)->where('event', 'updated')->exists())->toBeTrue();
});

test('marking the profile as reviewed records who and when', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $chief = $world->users['lga_chief'];

    $this->actingAs($chief)->post("/lgas/{$lga->ulid}/review")->assertRedirect();

    $this->get("/lgas/{$lga->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('lastReviewed.by', $chief->name)
        ->whereNot('lastReviewed.at', null));
});

test('users without lga_profiles.update cannot edit a profile they can see', function () {
    $world = JurisdictionWorld::build();
    SpatieRole::findByName('lga_security_chief')->revokePermissionTo('lga_profiles.update');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $url = '/lgas/'.$world->lgas['A1a']->ulid;

    $this->actingAs($world->users['lga_chief']->fresh())->put("{$url}/profile", ['terrain' => []])->assertForbidden();
    $this->post("{$url}/review")->assertForbidden();
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.updateProfile', false));
});

test('completeness counts history, economy, terrain, roads, borders and a community', function () {
    $profile = new LgaProfile(['terrain' => []]);

    expect(ProfileCompleteness::percent(null, 0))->toBe(0)
        ->and(ProfileCompleteness::percent($profile, 1))->toBe(17)
        ->and(ProfileCompleteness::missing($profile, 1))->toBe(['history', 'economic_activities', 'terrain', 'major_roads', 'borders']);

    $profile->fill(['history' => 'x', 'economic_activities' => '  ', 'terrain' => ['urban'], 'major_roads' => 'A1', 'borders' => 'B', 'waterways' => 'ignored']);

    expect(ProfileCompleteness::percent($profile, 0))->toBe(67)
        ->and(ProfileCompleteness::missing($profile, 0))->toBe(['economic_activities', 'communities'])
        ->and(ProfileCompleteness::percent($profile->fill(['economic_activities' => 'Trade']), 3))->toBe(100);
});

test('the list shows completeness, community counts and last review', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    LgaProfile::factory()->forLga($lga)->create(['history' => 'x', 'borders' => 'y', 'last_reviewed_at' => now()]);
    Community::factory()->in($lga)->count(2)->create();

    $this->actingAs($world->users['state_director'])->get('/lgas')->assertInertia(fn (Assert $page) => $page
        ->where('lgas.data', fn ($rows) => ($row = collect($rows)->firstWhere('ulid', $lga->ulid))
            && $row['completeness'] === 50
            && $row['communities'] === 2
            && $row['last_reviewed_at'] !== null));
});
