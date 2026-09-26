<?php

use App\Enums\Classification;
use App\Enums\SecurityNoteStatus;
use App\Models\Community;
use App\Models\CommunitySecurityNote;
use App\Models\RecordAccessLog;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Jurisdiction\JurisdictionWorld;

function notePayload(Community $community, array $overrides = []): array
{
    return [
        'community' => $community->ulid,
        'category' => 'land_dispute',
        'title' => 'Boundary tension at the market',
        'description' => 'Two families dispute the market land.',
        'classification' => 'restricted',
        'observed_at' => now()->subDays(2)->toDateString(),
        'status' => 'active',
        ...$overrides,
    ];
}

function systemContext(): void
{
    app(JurisdictionContext::class)->system();
}

test('a user cannot assign a level above their clearance through HTTP', function (string $who, string $level, bool $allowed) {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $community = Community::factory()->in($lga)->create();

    $response = $this->actingAs($world->users[$who])->post("/lgas/{$lga->ulid}/security-notes", notePayload($community, ['classification' => $level]));

    $allowed
        ? $response->assertSessionHasNoErrors()
        : $response->assertSessionHasErrors(['classification' => 'You cannot classify a record above your own clearance.']);
})->with([
    ['lga_chief', 'restricted', true],
    ['lga_chief', 'confidential', false],
    ['deputy_state_director', 'confidential', true],
    ['deputy_state_director', 'secret', false],
    ['state_director', 'secret', true],
    ['director_general', 'secret', true],
]);

test('editing cannot raise a note above the editor\'s clearance', function () {
    $world = JurisdictionWorld::build();
    $community = Community::factory()->in($world->lgas['A1a'])->create();
    $note = CommunitySecurityNote::factory()->in($community)->create(['created_by' => $world->users['lga_chief']->id]);
    $payload = notePayload($community, ['classification' => 'confidential']);
    unset($payload['status']);

    $this->actingAs($world->users['lga_chief'])->put("/security-notes/{$note->ulid}", $payload)->assertSessionHasErrors('classification');
    $this->flushSession();
    $this->actingAs($world->users['deputy_state_director'])->put("/security-notes/{$note->ulid}", $payload)->assertSessionHasNoErrors();

    systemContext();
    expect($note->fresh()->classification)->toBe(Classification::Confidential)
        ->and(Activity::query()->where('event', 'classification_changed')->where('subject_id', $note->id)->sole()->properties->only(['old', 'new'])->all())
        ->toBe(['old' => 'restricted', 'new' => 'confidential']);
});

test('a deputy state director sees confidential notes in their state, not the director\'s secret ones, and nothing elsewhere', function () {
    $world = JurisdictionWorld::build();
    $own = Community::factory()->in($world->lgas['A1b'])->create();
    $other = Community::factory()->in($world->lgas['B1'])->create();
    $director = $world->users['state_director'];
    $confidential = CommunitySecurityNote::factory()->in($own)->classified(Classification::Confidential)->create(['created_by' => $director->id]);
    $secret = CommunitySecurityNote::factory()->in($own)->classified(Classification::Secret)->create(['created_by' => $director->id]);
    $elsewhere = CommunitySecurityNote::factory()->in($other)->create();
    $deputy = $world->users['deputy_state_director'];

    $this->actingAs($deputy)->get("/security-notes/{$confidential->ulid}")->assertOk();
    $this->actingAs($deputy)->get("/security-notes/{$secret->ulid}")->assertNotFound();
    $this->actingAs($deputy)->get("/security-notes/{$elsewhere->ulid}")->assertNotFound();

    $this->actingAs($deputy)->get("/lgas/{$world->lgas['A1b']->ulid}?tab=security_notes")->assertInertia(fn (Assert $page) => $page
        ->where('securityNotes.list.data', fn ($rows) => collect($rows)->pluck('ulid')->all() === [$confidential->ulid]));

    $this->flushSession();
    $this->actingAs($director)->get("/security-notes/{$secret->ulid}")->assertOk();
});

test('reading confidential and secret notes is access-logged; restricted is not', function () {
    $world = JurisdictionWorld::build();
    $community = Community::factory()->in($world->lgas['A1a'])->create();
    $notes = collect(Classification::cases())->mapWithKeys(fn (Classification $level) => [
        $level->value => CommunitySecurityNote::factory()->in($community)->classified($level)->create(),
    ]);
    $director = $world->users['state_director'];

    foreach ($notes as $note) {
        $this->actingAs($director)->get("/security-notes/{$note->ulid}")->assertOk();
    }

    $logs = RecordAccessLog::query()->where('subject_type', CommunitySecurityNote::class)->get();

    expect($logs->pluck('subject_id')->sort()->values()->all())->toBe([$notes['confidential']->id, $notes['secret']->id])
        ->and($logs->every(fn ($log) => $log->user_id === $director->id && $log->action->value === 'view'))->toBeTrue();
});

test('status changes are logged; resolving needs a date not before the observation', function () {
    $world = JurisdictionWorld::build();
    $note = CommunitySecurityNote::factory()->in(Community::factory()->in($world->lgas['A1a'])->create())->create(['observed_at' => now()->subDays(5)->toDateString()]);
    $url = "/security-notes/{$note->ulid}/status";
    $this->actingAs($world->users['lga_chief']);

    $this->post($url, ['status' => 'resolved'])->assertSessionHasErrors('resolved_at');
    $this->post($url, ['status' => 'resolved', 'resolved_at' => now()->subDays(9)->toDateString()])->assertSessionHasErrors('resolved_at');
    $this->post($url, ['status' => 'monitoring'])->assertSessionHasNoErrors();
    $this->post($url, ['status' => 'resolved', 'resolved_at' => now()->subDay()->toDateString()])->assertSessionHasNoErrors();

    systemContext();
    expect($note->fresh()->status)->toBe(SecurityNoteStatus::Resolved)
        ->and($note->fresh()->resolved_at?->toDateString())->toBe(now()->subDay()->toDateString())
        ->and(Activity::query()->where('event', 'status_changed')->orderBy('id')->get()->map(fn ($a) => $a->properties->only(['old', 'new'])->all())->all())
        ->toBe([['old' => 'active', 'new' => 'monitoring'], ['old' => 'monitoring', 'new' => 'resolved']]);

    // The database insists on a resolution date too.
    expect(fn () => DB::transaction(fn () => $note->fresh()->forceFill(['resolved_at' => null])->save()))->toThrow(QueryException::class);
});

test('only State Directors and above delete notes, with a logged reason', function () {
    $world = JurisdictionWorld::build();
    $note = CommunitySecurityNote::factory()->in(Community::factory()->in($world->lgas['A1a'])->create())->create();
    $url = "/security-notes/{$note->ulid}";

    $this->actingAs($world->users['lga_chief'])->delete($url, ['reason' => 'x'])->assertForbidden();
    $this->flushSession();
    $this->actingAs($world->users['deputy_state_director'])->delete($url, ['reason' => 'x'])->assertForbidden();
    $this->flushSession();
    $this->actingAs($world->users['state_director'])->delete($url, [])->assertSessionHasErrors('reason');
    $this->actingAs($world->users['state_director'])->delete($url, ['reason' => 'Entered twice'])->assertRedirect();

    systemContext();
    expect(CommunitySecurityNote::query()->find($note->id))->toBeNull()
        ->and(Activity::query()->where('event', 'security_note_deleted')->sole()->properties['reason'])->toBe('Entered twice');
});

test('notes need a community of the LGA and a past observation date', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $otherLgaCommunity = Community::factory()->in($world->lgas['A1b'])->create();
    $this->actingAs($world->users['state_director']);

    $this->post("/lgas/{$lga->ulid}/security-notes", notePayload($otherLgaCommunity))->assertSessionHasErrors('community');
    $this->post("/lgas/{$lga->ulid}/security-notes", notePayload(Community::factory()->in($lga)->create(), ['observed_at' => now()->addDay()->toDateString()]))->assertSessionHasErrors('observed_at');
});
