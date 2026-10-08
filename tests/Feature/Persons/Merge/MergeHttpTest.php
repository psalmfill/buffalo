<?php

use App\Enums\MergeRequestStatus;
use App\Models\Person;
use App\Models\PersonMerge;
use App\Models\PersonMergeRequest;
use App\Models\RecordAccessLog;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Persons\MergeFixtures;

/*
| Phase 10A.7 / 10A.8: pages and endpoints, per role, with step-up and the
| merged-record redirect.
*/

beforeEach(function () {
    $this->world = JurisdictionWorld::build();
    $this->sd = $this->world->users['state_director'];
    CrossFixtures::system();
    $this->a = Person::factory()->in($this->world->lgas['A1a'])->named('Garba', 'Musa')->create(['nin' => '12121212121']);
    $this->b = Person::factory()->in($this->world->lgas['A1a'])->named('Garba', 'Mussa')->create(['nin' => '12121212121']);
});

function mergePayload(Person $survivor, Person $source, array $extra = []): array
{
    return ['survivor' => $survivor->ulid, 'source' => $source->ulid, 'place' => 'survivor', 'fields' => ['last_name' => 'survivor'], ...$extra];
}

test('the duplicates page per role', function (string $who, int $status, int $pairs) {
    $response = CrossFixtures::signIn($this->world->users[$who])->get('/persons/duplicates?state='.$this->world->states['A1']->ulid)->assertStatus($status);

    if ($status === 200) {
        $response->assertInertia(fn (Assert $page) => $page->component('persons/duplicates')->has('pairs', $pairs));
    }
})->with([
    ['super_admin', 403, 0],
    ['director_general', 200, 1],
    ['ddg_zone_a', 200, 1],
    ['ddg_zone_b', 200, 0],
    ['state_director', 200, 1],
    ['deputy_state_director', 200, 1],
    ['lga_chief', 200, 1],
]);

test('the review page: State Directors and national users, own jurisdiction only, access-logged', function (string $who, int $status) {
    CrossFixtures::signIn($this->world->users[$who])->get("/persons/merge?a={$this->a->ulid}&b={$this->b->ulid}")->assertStatus($status);
})->with([
    ['super_admin', 404],
    ['director_general', 200],
    ['ddg_zone_a', 200],
    ['ddg_zone_b', 404],
    ['state_director', 200],
    ['deputy_state_director', 403],
    ['lga_chief', 403],
]);

test('the review page shows the conflicts and logs both reads; no numeric ids or prior values in props', function () {
    $response = CrossFixtures::signIn($this->sd)->get("/persons/merge?a={$this->a->ulid}&b={$this->b->ulid}")
        ->assertInertia(fn (Assert $page) => $page->component('persons/merge')
            ->where('blockers', [])
            ->where('fields.1.field', 'last_name')
            ->where('fields.1.conflict', true));

    $props = json_encode($response->viewData('page')['props']);
    expect(RecordAccessLog::query()->where('context', 'merge_review')->count())->toBe(2)
        ->and($props)->not->toContain('"id":')
        ->and($props)->not->toContain('survivor_prior_values');
});

test('another state\'s State Director gets 404 for the review page', function () {
    CrossFixtures::signIn(CrossFixtures::directorOfB1($this->world))->get("/persons/merge?a={$this->a->ulid}&b={$this->b->ulid}")->assertNotFound();
});

test('merging needs step-up, then merges and redirects to the survivor', function () {
    CrossFixtures::signInWithoutStepUp($this->sd)->withHeader('X-Inertia', 'true')
        ->post('/persons/merge', mergePayload($this->a, $this->b))
        ->assertStatus(428);
    expect(MergeFixtures::reload($this->b)->trashed())->toBeFalse();

    CrossFixtures::signIn($this->sd)->post('/persons/merge', mergePayload($this->a, $this->b))
        ->assertRedirect("/persons/{$this->a->ulid}")
        ->assertSessionHasNoErrors();

    expect(MergeFixtures::reload($this->b)->merged_into_person_id)->toBe($this->a->id);
});

test('a NIN conflict is refused with a message, nothing changes', function () {
    $other = Person::factory()->in($this->world->lgas['A1a'])->create(['nin' => '99999999999']);

    CrossFixtures::signIn($this->sd)->post('/persons/merge', mergePayload($this->a, $other))
        ->assertSessionHasErrors(['merge' => 'The records have different NINs: they are different people and cannot be merged.']);

    expect(MergeFixtures::reload($other)->trashed())->toBeFalse();
});

test('a Deputy State Director and an LGA chief cannot merge', function (string $who) {
    CrossFixtures::signIn($this->world->users[$who])->post('/persons/merge', mergePayload($this->a, $this->b))->assertForbidden();
})->with(['deputy_state_director', 'lga_chief']);

test('a merged record\'s URL redirects to the survivor, or 404s', function () {
    $c = Person::factory()->in($this->world->lgas['A1b'])->create();
    MergeFixtures::merge($this->sd, $this->a, $this->b);
    // A chain: b -> a, then a -> c (in another LGA).
    MergeFixtures::merge($this->sd, $c, $this->a);

    CrossFixtures::signIn($this->sd)->get("/persons/{$this->b->ulid}")
        ->assertRedirect("/persons/{$c->ulid}")
        ->assertSessionHas('toast.message', 'That record was merged into this one.');
    CrossFixtures::signIn($this->sd)->get("/persons/{$this->a->ulid}")->assertRedirect("/persons/{$c->ulid}");

    // The A1a chief cannot see the final survivor (A1b): 404, as for any record outside their reach.
    CrossFixtures::signIn($this->world->users['lga_chief'])->get("/persons/{$this->b->ulid}")->assertNotFound();
    CrossFixtures::signIn(CrossFixtures::directorOfB1($this->world))->get("/persons/{$this->b->ulid}")->assertNotFound();
    CrossFixtures::signIn($this->sd)->get('/persons/'.strtolower((string) Str::ulid()))->assertNotFound();
});

test('the survivor page lists merged records; history and undo', function () {
    MergeFixtures::merge($this->sd, $this->a, $this->b);
    $merge = PersonMerge::query()->withoutGlobalScopes()->firstOrFail();

    CrossFixtures::signIn($this->sd)->get("/persons/{$this->a->ulid}")
        ->assertInertia(fn (Assert $page) => $page->has('merges', 1)
            ->where('merges.0.source.ulid', $this->b->ulid)
            ->where('merges.0.can.undo', true)
            ->missing('merges.0.survivor_prior_values'));

    CrossFixtures::signIn($this->world->users['lga_chief'])->get("/persons/{$this->a->ulid}")
        ->assertInertia(fn (Assert $page) => $page->has('merges', 1)->where('merges.0.can.undo', false));

    CrossFixtures::signIn($this->sd)->get("/persons/merges/{$merge->ulid}/history")
        ->assertInertia(fn (Assert $page) => $page->component('persons/merged-history')->where('source.ulid', $this->b->ulid));
    CrossFixtures::signIn(CrossFixtures::directorOfB1($this->world))->get("/persons/merges/{$merge->ulid}/history")->assertNotFound();

    // Undo: step-up, permission, jurisdiction.
    CrossFixtures::signIn($this->world->users['deputy_state_director'])->post("/persons/merges/{$merge->ulid}/undo", ['reason' => 'Merged in error by mistake.'])->assertForbidden();
    CrossFixtures::signIn(CrossFixtures::directorOfB1($this->world))->post("/persons/merges/{$merge->ulid}/undo", ['reason' => 'Merged in error by mistake.'])->assertNotFound();
    CrossFixtures::signInWithoutStepUp($this->sd)->withHeader('X-Inertia', 'true')->post("/persons/merges/{$merge->ulid}/undo", ['reason' => 'Merged in error by mistake.'])->assertStatus(428);
    CrossFixtures::signIn($this->sd)->post("/persons/merges/{$merge->ulid}/undo", ['reason' => 'Merged in error by mistake.'])
        ->assertRedirect("/persons/{$this->a->ulid}")->assertSessionHasNoErrors();

    expect(MergeFixtures::reload($this->b)->trashed())->toBeFalse()
        ->and(DB::table('person_merges')->where('id', $merge->id)->value('survivor_prior_values'))->toBeNull();
});

test('merge requests: chiefs and deputies request on their own records; State Directors decline or execute', function () {
    $chief = $this->world->users['lga_chief'];
    $elsewhere = Person::factory()->in($this->world->lgas['A1b'])->create();
    $reason = 'Same NIN and the same village; spelling of the surname differs.';

    // Not their own record: 404. State Directors execute instead of requesting: 403.
    CrossFixtures::signIn($chief)->post('/persons/merge-requests', ['person' => $this->a->ulid, 'other' => $elsewhere->ulid, 'reason' => $reason])->assertNotFound();
    CrossFixtures::signIn($this->sd)->post('/persons/merge-requests', ['person' => $this->a->ulid, 'other' => $this->b->ulid, 'reason' => $reason])->assertForbidden();
    CrossFixtures::signIn($chief)->post('/persons/merge-requests', ['person' => $this->a->ulid, 'other' => $this->b->ulid, 'reason' => 'Too short'])->assertSessionHasErrors('reason');

    CrossFixtures::signIn($chief)->post('/persons/merge-requests', ['person' => $this->a->ulid, 'other' => $this->b->ulid, 'suggested_survivor' => $this->a->ulid, 'reason' => $reason])
        ->assertSessionHasNoErrors();
    CrossFixtures::signIn($chief)->post('/persons/merge-requests', ['person' => $this->b->ulid, 'other' => $this->a->ulid, 'reason' => $reason])
        ->assertSessionHasErrors(['other' => 'A merge of these two records has already been requested.']);

    $request = PersonMergeRequest::query()->withoutGlobalScopes()->firstOrFail();
    expect($request->suggested_survivor_id)->toBe($this->a->id)->and($request->lga_id)->toBe($this->world->lgas['A1a']->id);

    CrossFixtures::signIn($this->sd)->get('/persons/merge-requests')
        ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.can.decide', true));
    CrossFixtures::signIn($this->world->users['deputy_state_director'])->get('/persons/merge-requests')
        ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.can.decide', false));
    CrossFixtures::signIn(CrossFixtures::directorOfB1($this->world))->get('/persons/merge-requests')
        ->assertInertia(fn (Assert $page) => $page->has('requests.data', 0));

    CrossFixtures::signIn($this->world->users['deputy_state_director'])->post("/persons/merge-requests/{$request->ulid}/decline", ['reason' => 'Different fathers named.'])->assertForbidden();
    CrossFixtures::signIn(CrossFixtures::directorOfB1($this->world))->post("/persons/merge-requests/{$request->ulid}/decline", ['reason' => 'Different fathers named.'])->assertNotFound();

    // Executing through the review page marks the request executed.
    CrossFixtures::signIn($this->sd)->get("/persons/merge?a={$this->a->ulid}&b={$this->b->ulid}&request={$request->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('request.ulid', $request->ulid));
    CrossFixtures::signIn($this->sd)->post('/persons/merge', mergePayload($this->a, $this->b, ['request' => $request->ulid]))->assertSessionHasNoErrors();

    expect($request->fresh()?->status)->toBe(MergeRequestStatus::Executed);
});

test('a merge request can be declined with a note', function () {
    $chief = $this->world->users['lga_chief'];
    CrossFixtures::signIn($chief)->post('/persons/merge-requests', ['person' => $this->a->ulid, 'other' => $this->b->ulid, 'reason' => 'Same NIN and the same village; surname spelt differently.']);
    $request = PersonMergeRequest::query()->withoutGlobalScopes()->firstOrFail();

    CrossFixtures::signIn($this->sd)->post("/persons/merge-requests/{$request->ulid}/decline", ['reason' => 'Different fathers named in the records.'])->assertSessionHasNoErrors();

    expect($request->fresh()?->status)->toBe(MergeRequestStatus::Declined)
        ->and($request->fresh()?->decision_note)->toBe('Different fathers named in the records.');
});

test('not a duplicate: State Directors and above, with a reason; the pair disappears', function () {
    CrossFixtures::signIn($this->world->users['deputy_state_director'])->post('/persons/duplicates/dismiss', ['a' => $this->a->ulid, 'b' => $this->b->ulid, 'reason' => 'Twins: different people.'])->assertForbidden();
    CrossFixtures::signIn($this->sd)->post('/persons/duplicates/dismiss', ['a' => $this->a->ulid, 'b' => $this->b->ulid, 'reason' => 'Twins: different people.'])->assertSessionHasNoErrors();

    CrossFixtures::signIn($this->sd)->get('/persons/duplicates')->assertInertia(fn (Assert $page) => $page->has('pairs', 0));
});

test('the picker\'s own mode never offers records seen under a grant', function () {
    $chief = $this->world->users['lga_chief'];
    CrossFixtures::person($this->world->lgas['A1b']);
    app(JurisdictionContext::class)->actingAs($chief, fn () => CrossFixtures::grant($chief, $this->sd));

    $names = fn (bool $own) => collect(CrossFixtures::signIn($chief)->postJson('/persons/picker', ['q' => 'Garba', 'own' => $own])->json('results'))->pluck('name')->sort()->values()->all();

    expect($names(false))->toBe(['Garba Danjuma Musa', 'Garba Musa', 'Garba Mussa'])
        ->and($names(true))->toBe(['Garba Musa', 'Garba Mussa']);
});
