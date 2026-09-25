<?php

use App\Models\Community;
use App\Models\Lga;
use App\Models\Ward;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/** A square boundary around (6.25, 3.25) for an LGA. */
function giveBoundary(Lga $lga): void
{
    DB::statement("UPDATE lgas SET boundary = ST_GeogFromText('SRID=4326;MULTIPOLYGON(((3.0 6.0, 3.5 6.0, 3.5 6.5, 3.0 6.5, 3.0 6.0)))') WHERE id = ?", [$lga->id]);
}

/** Assertions read the database in system context, as setup does. */
function asSystem(): void
{
    app(JurisdictionContext::class)->system();
}

function communityPayload(array $overrides = []): array
{
    return ['name' => 'Oke-Ado', 'type' => 'town', 'other_names' => [], ...$overrides];
}

test('an LGA chief adds a community with a location, which is audited', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $chief = $world->users['lga_chief'];

    $this->actingAs($chief)->post("/lgas/{$lga->ulid}/communities", communityPayload([
        'other_names' => ['Oke Ado', ' '],
        'latitude' => '6.25',
        'longitude' => '3.25',
        'population_estimate' => '1200',
        'notes' => 'Market town',
    ]))->assertRedirect();

    asSystem();
    $community = Community::query()->withCoordinates()->where('name', 'Oke-Ado')->sole();

    expect($community->lga_id)->toBe($lga->id)
        ->and($community->state_id)->toBe($lga->state_id)
        ->and($community->other_names)->toBe(['Oke Ado'])
        ->and($community->latitude)->toBe(6.25)
        ->and($community->longitude)->toBe(3.25)
        ->and($community->created_by)->toBe($chief->id);

    expect(Activity::query()->where('subject_id', $community->id)->where('subject_type', Community::class)->pluck('event')->sort()->values()->all())
        ->toBe(['created', 'location_changed']);
});

test('names are unique per LGA, case-insensitively, and reusable after deletion', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $existing = Community::factory()->in($lga)->create(['name' => 'Ajegunle']);
    Community::factory()->in($world->lgas['A1b'])->create(['name' => 'Oke-Ado']);
    $this->actingAs($world->users['state_director']);

    $this->post("/lgas/{$lga->ulid}/communities", communityPayload(['name' => 'AJEGUNLE']))->assertSessionHasErrors('name');
    // Another LGA may have the same name.
    $this->post("/lgas/{$lga->ulid}/communities", communityPayload())->assertSessionHasNoErrors();

    // The database enforces it too.
    asSystem();
    expect(fn () => DB::transaction(fn () => Community::factory()->in($lga)->create(['name' => 'ajegunle'])))->toThrow(QueryException::class);

    $this->delete("/communities/{$existing->ulid}", ['reason' => 'Duplicate entry'])->assertRedirect();
    $this->post("/lgas/{$lga->ulid}/communities", communityPayload(['name' => 'Ajegunle']))->assertSessionHasNoErrors();

    asSystem();
    expect(Community::query()->where('lga_id', $lga->id)->whereRaw("lower(name) = 'ajegunle'")->count())->toBe(1)
        ->and(Community::withTrashed()->where('lga_id', $lga->id)->whereRaw("lower(name) = 'ajegunle'")->count())->toBe(2);
});

test('an edit may keep its own name but not take another community\'s', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $one = Community::factory()->in($lga)->create(['name' => 'One']);
    Community::factory()->in($lga)->create(['name' => 'Two']);
    $this->actingAs($world->users['lga_chief']);

    $this->put("/communities/{$one->ulid}", communityPayload(['name' => 'one']))->assertSessionHasNoErrors();
    $this->put("/communities/{$one->ulid}", communityPayload(['name' => 'TWO']))->assertSessionHasErrors('name');
});

test('the ward must belong to the community\'s LGA', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $own = Ward::factory()->create(['lga_id' => $lga->id, 'state_id' => $lga->state_id]);
    $other = Ward::factory()->create(['lga_id' => $world->lgas['A1b']->id, 'state_id' => $lga->state_id]);
    $this->actingAs($world->users['state_director']);

    $this->post("/lgas/{$lga->ulid}/communities", communityPayload(['ward' => $other->ulid]))->assertSessionHasErrors('ward');
    $this->post("/lgas/{$lga->ulid}/communities", communityPayload(['ward' => $own->ulid]))->assertSessionHasNoErrors();
    asSystem();
    expect(Community::query()->where('name', 'Oke-Ado')->sole()->ward_id)->toBe($own->id);

    // The database refuses a ward of another LGA.
    expect(fn () => DB::transaction(fn () => Community::factory()->in($lga)->create(['ward_id' => $other->id])))->toThrow(QueryException::class);
});

test('a location outside the LGA boundary is rejected when the LGA has one', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $this->actingAs($world->users['state_director']);

    // No boundary yet: any point in Nigeria.
    $this->post("/lgas/{$lga->ulid}/communities", communityPayload(['name' => 'Far', 'latitude' => 11.0, 'longitude' => 8.0]))->assertSessionHasNoErrors();

    giveBoundary($lga);

    $this->post("/lgas/{$lga->ulid}/communities", communityPayload(['name' => 'Outside', 'latitude' => 7.0, 'longitude' => 3.25]))
        ->assertSessionHasErrors(['longitude' => "The location is outside the boundary of {$lga->name} LGA."]);
    $this->post("/lgas/{$lga->ulid}/communities", communityPayload(['name' => 'Inside', 'latitude' => 6.2, 'longitude' => 3.3]))->assertSessionHasNoErrors();

    // Outside Nigeria is always rejected.
    $this->post("/lgas/{$lga->ulid}/communities", communityPayload(['name' => 'Abroad', 'latitude' => 51.5, 'longitude' => -0.1]))->assertSessionHasErrors(['latitude', 'longitude']);
    // Both coordinates or neither.
    $this->post("/lgas/{$lga->ulid}/communities", communityPayload(['name' => 'Half', 'latitude' => 6.2]))->assertSessionHasErrors('longitude');

    // The database refuses it too.
    asSystem();
    expect(fn () => DB::transaction(fn () => Community::factory()->in($lga)->at(7.0, 3.25)->create()))->toThrow(QueryException::class, 'outside its LGA boundary');
});

test('LGA chiefs and deputies cannot delete communities; a State Director can, with a logged reason', function () {
    $world = JurisdictionWorld::build();
    $community = Community::factory()->in($world->lgas['A1a'])->create(['name' => 'Oke-Ado']);
    $url = "/communities/{$community->ulid}";

    $this->actingAs($world->users['lga_chief'])->delete($url, ['reason' => 'x'])->assertForbidden();
    $this->flushSession();
    $this->actingAs($world->users['deputy_state_director'])->delete($url, ['reason' => 'x'])->assertForbidden();
    $this->flushSession();

    $director = $world->users['state_director'];
    $this->actingAs($director)->delete($url, [])->assertSessionHasErrors('reason');
    $this->actingAs($director)->delete($url, ['reason' => 'Merged into Ado-Ekiti'])->assertRedirect();

    asSystem();
    expect(Community::query()->find($community->id))->toBeNull()
        ->and(Community::withTrashed()->find($community->id)?->trashed())->toBeTrue();

    $event = Activity::query()->where('event', 'community_deleted')->sole();
    expect($event->properties['reason'])->toBe('Merged into Ado-Ekiti')
        ->and($event->causer_id)->toBe($director->id)
        ->and($event->subject_id)->toBe($community->id);

    $this->actingAs($director)->get($url)->assertNotFound();
});

test('a community outside the jurisdiction is 404', function () {
    $world = JurisdictionWorld::build();
    $other = Community::factory()->in($world->lgas['B1'])->create();
    $this->actingAs($world->users['state_director']);

    foreach (['get' => '', 'get ' => '/edit', 'put' => '', 'delete' => ''] as $method => $suffix) {
        $this->{trim($method)}("/communities/{$other->ulid}{$suffix}", communityPayload(['reason' => 'x']))->assertNotFound();
    }

    $this->flushSession();
    $this->actingAs($world->users['super_admin'])->get("/communities/{$other->ulid}")->assertNotFound();
    $this->actingAs($world->users['super_admin'])->get('/communities')->assertForbidden();
});

test('the community page shows details, map and its history', function () {
    $world = JurisdictionWorld::build();
    $community = Community::factory()->in($world->lgas['A1a'])->at(6.4, 3.4)->create(['name' => 'Oke-Ado']);
    $this->actingAs($world->users['lga_chief']);

    $this->get("/communities/{$community->ulid}")->assertInertia(fn (Assert $page) => $page
        ->component('communities/show')
        ->where('community.latitude', 6.4)
        ->where('marker.title', 'Oke-Ado')
        ->where('can.update', true)
        ->where('can.delete', false)
        ->where('history', null));

    $this->put("/communities/{$community->ulid}", communityPayload(['name' => 'Oke Ado', 'latitude' => 6.41, 'longitude' => 3.4]));

    $this->get("/communities/{$community->ulid}?tab=history")->assertInertia(fn (Assert $page) => $page
        ->where('tab', 'history')
        ->where('history.data', fn ($entries) => collect($entries)->pluck('event')->contains('location_changed')
            && collect($entries)->pluck('event')->contains('updated')));
});

test('fuzzy search finds misspellings, accents and other names, only within the jurisdiction', function () {
    $world = JurisdictionWorld::build();
    Community::factory()->in($world->lgas['A1a'])->create(['name' => 'Ajegunle']);
    Community::factory()->in($world->lgas['A1b'])->create(['name' => 'Ìbàdàn Road', 'other_names' => []]);
    Community::factory()->in($world->lgas['A1b'])->create(['name' => 'New Town', 'other_names' => ['Old Oyingbo']]);
    Community::factory()->in($world->lgas['B1'])->create(['name' => 'Ajegunle']);

    $search = fn (string $who, string $q) => app(JurisdictionContext::class)->actingAs(
        $world->users[$who],
        fn () => Community::query()->search($q)->with('lga')->get()->map(fn ($c) => $c->name.'@'.array_search($c->lga_id, array_map(fn ($l) => $l->id, $world->lgas), true))->all(),
    );

    expect($search('state_director', 'Ajegunlle'))->toBe(['Ajegunle@A1a'])
        ->and($search('state_director', 'ajegunle'))->toBe(['Ajegunle@A1a'])
        ->and($search('state_director', 'ibadan'))->toBe(['Ìbàdàn Road@A1b'])
        ->and($search('state_director', 'Oyingbo'))->toBe(['New Town@A1b'])
        ->and($search('state_director', 'Oyingboo'))->toBe(['New Town@A1b'])
        ->and($search('director_general', 'Ajegunle'))->toHaveCount(2)
        ->and($search('lga_chief', 'Oyingbo'))->toBe([])
        ->and($search('super_admin', 'Ajegunle'))->toBe([]);

    $this->actingAs($world->users['state_director'])->get('/communities?q=Ajegunlle')
        ->assertInertia(fn (Assert $page) => $page->component('communities/index')->has('results.data', 1)->where('results.data.0.lga.ulid', $world->lgas['A1a']->ulid));
});
