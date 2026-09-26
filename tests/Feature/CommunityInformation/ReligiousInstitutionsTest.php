<?php

use App\Models\Community;
use App\Models\Denomination;
use App\Models\Religion;
use App\Models\ReligiousInstitution;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Jurisdiction\JurisdictionWorld;

function institutionPayload(Community $community, Religion $religion, array $overrides = []): array
{
    return ['community' => $community->ulid, 'religion' => $religion->ulid, 'name' => 'Central Mosque', 'type' => 'mosque', ...$overrides];
}

test('an LGA chief adds a religious institution with a location', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $community = Community::factory()->in($lga)->create();
    $islam = Religion::factory()->create();
    $sunni = Denomination::factory()->for($islam)->create();

    $this->actingAs($world->users['lga_chief'])->post("/lgas/{$lga->ulid}/religious-institutions", institutionPayload($community, $islam, [
        'denomination' => $sunni->ulid, 'estimated_congregation' => '800', 'latitude' => '6.3', 'longitude' => '3.3',
    ]))->assertRedirect("/lgas/{$lga->ulid}?tab=religious_institutions");

    app(JurisdictionContext::class)->system();
    $institution = ReligiousInstitution::query()->withCoordinates()->sole();
    expect($institution->community_id)->toBe($community->id)
        ->and($institution->lga_id)->toBe($lga->id)
        ->and($institution->state_id)->toBe($lga->state_id)
        ->and($institution->denomination_id)->toBe($sunni->id)
        ->and($institution->latitude)->toBe(6.3)
        ->and(Activity::query()->where('subject_id', $institution->id)->where('subject_type', ReligiousInstitution::class)->pluck('event')->sort()->values()->all())
        ->toBe(['created', 'location_changed']);
});

test('the community must belong to the LGA and the denomination to the religion', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $community = Community::factory()->in($lga)->create();
    $otherCommunity = Community::factory()->in($world->lgas['A1b'])->create();
    $islam = Religion::factory()->create();
    $christianity = Religion::factory()->create();
    $catholic = Denomination::factory()->for($christianity)->create();
    $this->actingAs($world->users['state_director']);

    $this->post("/lgas/{$lga->ulid}/religious-institutions", institutionPayload($otherCommunity, $islam))->assertSessionHasErrors('community');
    $this->post("/lgas/{$lga->ulid}/religious-institutions", institutionPayload($community, $islam, ['denomination' => $catholic->ulid]))
        ->assertSessionHasErrors(['denomination' => 'The denomination must belong to the selected religion.']);

    // The database agrees on both.
    app(JurisdictionContext::class)->system();
    expect(fn () => DB::transaction(fn () => ReligiousInstitution::factory()->in($community)->create(['religion_id' => $islam->id, 'denomination_id' => $catholic->id])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => ReligiousInstitution::factory()->create(['community_id' => $otherCommunity->id, 'lga_id' => $lga->id, 'state_id' => $lga->state_id])))->toThrow(QueryException::class);
});

test('deactivated religions cannot be chosen for new institutions', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $retired = Religion::factory()->inactive()->create();

    $this->actingAs($world->users['state_director'])->post("/lgas/{$lga->ulid}/religious-institutions", institutionPayload(Community::factory()->in($lga)->create(), $retired))
        ->assertSessionHasErrors(['religion' => 'The selected religion is no longer available.']);
});

test('the LGA tab counts institutions by religion and type (computed)', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $community = Community::factory()->in($lga)->create();
    $islam = Religion::factory()->create(['name' => 'Islam']);
    $christianity = Religion::factory()->create(['name' => 'Christianity']);
    ReligiousInstitution::factory()->in($community)->count(2)->create(['religion_id' => $islam->id, 'type' => 'mosque']);
    ReligiousInstitution::factory()->in($community)->create(['religion_id' => $christianity->id, 'type' => 'church']);
    ReligiousInstitution::factory()->in(Community::factory()->in($world->lgas['A1b'])->create())->create(['religion_id' => $islam->id]);

    $this->actingAs($world->users['lga_chief'])->get("/lgas/{$lga->ulid}?tab=religious_institutions")->assertInertia(fn (Assert $page) => $page
        ->where('religiousInstitutions.summary.total', 3)
        ->where('religiousInstitutions.summary.byReligion', [['label' => 'Christianity', 'count' => 1], ['label' => 'Islam', 'count' => 2]])
        ->where('religiousInstitutions.summary.byType', [['label' => 'Church', 'count' => 1], ['label' => 'Mosque', 'count' => 2]]));
});

test('only State Directors and above delete institutions, with a logged reason', function () {
    $world = JurisdictionWorld::build();
    $institution = ReligiousInstitution::factory()->in(Community::factory()->in($world->lgas['A1a'])->create())->create();
    $url = "/religious-institutions/{$institution->ulid}";

    $this->actingAs($world->users['lga_chief'])->delete($url, ['reason' => 'x'])->assertForbidden();
    $this->flushSession();
    $this->actingAs($world->users['deputy_state_director'])->delete($url, ['reason' => 'x'])->assertForbidden();
    $this->flushSession();
    $this->actingAs($world->users['state_director'])->delete($url, ['reason' => 'Closed down'])->assertRedirect();

    app(JurisdictionContext::class)->system();
    expect(ReligiousInstitution::query()->find($institution->id))->toBeNull()
        ->and(Activity::query()->where('event', 'religious_institution_deleted')->sole()->properties['reason'])->toBe('Closed down');
});

test('an institution outside the jurisdiction is 404', function () {
    $world = JurisdictionWorld::build();
    $institution = ReligiousInstitution::factory()->in(Community::factory()->in($world->lgas['B1'])->create())->create();

    $this->actingAs($world->users['state_director'])->get("/religious-institutions/{$institution->ulid}/edit")->assertNotFound();
});
