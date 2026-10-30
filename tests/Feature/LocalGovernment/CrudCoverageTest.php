<?php

use App\Enums\PoliticalOfficeType;
use App\Models\Community;
use App\Models\Lga;
use App\Models\PoliticalOffice;
use App\Models\User;
use App\Models\Ward;
use App\Support\Exports\Lists\Exporters\WardsExporter;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/*
| CRUD coverage step: New community on /communities (LGAs from
| JurisdictionOptions where LgaPolicy::addCommunity allows), the LGA page's
| Wards tab (reference data; communities through their scope), and Edit on
| the Leadership tabs' office rows (PoliticalOfficePolicy::update).
*/

beforeEach(function () {
    Notification::fake();
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->a1a = $this->world->lgas['A1a'];
    $this->a1b = $this->world->lgas['A1b'];
    $this->b1 = $this->world->lgas['B1'];
});

function createLgas(User $user): array
{
    return collect(CrossFixtures::signIn($user)->get('/communities')->assertOk()->viewData('page')['props']['createLgas'])->pluck('ulid')->sort()->values()->all();
}

test('New community offers exactly the active LGAs where the user may add a community', function () {
    $closed = Lga::factory()->inactive()->create(['senatorial_district_id' => $this->a1a->senatorial_district_id]);
    $sorted = fn (Lga ...$lgas) => collect($lgas)->pluck('ulid')->sort()->values()->all();

    expect(createLgas($this->world->users['lga_chief']))->toBe($sorted($this->a1a))
        ->and(createLgas($this->world->users['state_director']))->toBe($sorted($this->a1a, $this->a1b))
        ->and(createLgas($this->world->users['deputy_state_director']))->toBe($sorted($this->a1a, $this->a1b))
        ->and(createLgas($this->world->users['director_general']))->toContain($this->b1->ulid)
        ->not->toContain($closed->ulid);

    CrossFixtures::signIn($this->world->users['super_admin'])->get('/communities')->assertForbidden();
});

test('the chosen LGA leads to the existing create form, which keeps its own checks', function () {
    $chief = $this->world->users['lga_chief'];

    CrossFixtures::signIn($chief)->get("/lgas/{$this->a1a->ulid}/communities/create")->assertOk();
    CrossFixtures::signIn($chief)->get("/lgas/{$this->b1->ulid}/communities/create")->assertNotFound();
});

test('the Wards tab lists the LGA\'s wards, deactivated ones last, with communities through their scope', function () {
    $active = Ward::factory()->create(['lga_id' => $this->a1a->id, 'name' => 'Beta Ward']);
    $closed = Ward::factory()->inactive()->create(['lga_id' => $this->a1a->id, 'name' => 'Alpha Ward']);
    Community::factory()->in($this->a1a)->count(2)->create(['ward_id' => $active->id]);

    $page = CrossFixtures::signIn($this->world->users['lga_chief'])->get("/lgas/{$this->a1a->ulid}?tab=wards")->assertOk()->viewData('page')['props'];

    expect($page['tabs'])->toContain('wards')
        ->and(collect($page['wards'])->pluck('ulid')->all())->toBe([$active->ulid, $closed->ulid])
        ->and($page['wards'][0]['communities'])->toBe(2)
        ->and($page['wards'][1]['is_active'])->toBeFalse();

    // The export lists the same rows for the same LGA.
    $chief = $this->world->users['lga_chief'];
    $exporter = app(WardsExporter::class);
    $exported = app(JurisdictionContext::class)->actingAs($chief, fn () => $exporter->query($chief, ['lga' => $this->a1a->ulid])->pluck('ulid')->all());
    expect($exported)->toBe([$active->ulid, $closed->ulid]);

    // Another LGA's wards are never exported to the chief.
    Ward::factory()->create(['lga_id' => $this->b1->id]);
    $outside = app(JurisdictionContext::class)->actingAs($chief, fn () => $exporter->query($chief, ['lga' => $this->b1->ulid])->count());
    expect($outside)->toBe(0);
});

test('the Wards tab is hidden when the LGA has no wards', function () {
    CrossFixtures::signIn($this->world->users['state_director'])->get("/lgas/{$this->a1b->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('tabs', fn ($tabs) => ! collect($tabs)->contains('wards')));
});

test('Leadership rows offer Edit exactly where the office policy allows it', function () {
    $person = CrossFixtures::person($this->a1a, ['first_name' => 'Ade', 'last_name' => 'Chairman', 'other_names' => null]);
    $office = PoliticalOffice::factory()->forPerson($person)->inLga(PoliticalOfficeType::LgaChairman, $this->a1a)->create();

    foreach (['lga_chief', 'state_director', 'director_general'] as $role) {
        $user = $this->world->users[$role];
        $row = collect(CrossFixtures::signIn($user)->get("/lgas/{$this->a1a->ulid}?tab=leadership")->viewData('page')['props']['leadership']['chairman'])->firstWhere('ulid', $office->ulid);

        $expected = app(JurisdictionContext::class)->actingAs($user, fn () => ['update' => $user->can('update', $office), 'delete' => $user->can('delete', $office)]);

        expect($row['can'])->toBe($expected)
            ->and($row['can']['update'])->toBeTrue();
    }

    // The chief may edit but not delete (political.delete is SD and above).
    $chief = $this->world->users['lga_chief'];
    expect(app(JurisdictionContext::class)->actingAs($chief, fn () => $chief->can('delete', $office)))->toBeFalse();
});
