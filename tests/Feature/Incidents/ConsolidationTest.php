<?php

use App\Enums\Classification;
use App\Enums\IncidentStatus;
use App\Models\CriminalGroup;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\IncidentConsolidation;
use App\Models\IncidentEvidence;
use App\Models\IncidentPerson;
use App\Models\Person;
use App\Models\PropertyType;
use App\Models\SecurityAgency;
use App\Models\User;
use App\Notifications\IncidentNotification;
use App\Support\Audit\AuditChain;
use App\Support\Dashboard\IncidentFigures;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Incidents\WorkflowFixtures as W;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/*
| Phase 10B.1 / 10B.2: consolidating a duplicate incident into the original
| while invalidating it.
*/

beforeEach(function () {
    Notification::fake();
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->chief = $this->world->users['lga_chief'];
    $this->sd = $this->world->users['state_director'];
    $this->lga = $this->world->lgas['A1a'];
    $this->original = W::at(W::draft($this->lga, $this->chief, ['title' => 'Kidnapping at the toll gate']), IncidentStatus::Submitted);
    $this->duplicate = W::at(W::draft($this->lga, $this->chief, ['title' => 'Travellers abducted near toll gate']), IncidentStatus::Submitted);
});

function consEvidence(Incident $incident, User $by, Classification $level = Classification::Restricted, string $title = 'Scene photo'): int
{
    return DB::table('incident_evidence')->insertGetId([
        'ulid' => strtolower((string) Str::ulid()), 'incident_id' => $incident->id, 'state_id' => $incident->state_id, 'lga_id' => $incident->lga_id,
        'title' => $title, 'evidence_type' => 'image', 'original_filename' => 'scene.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1234,
        'sha256' => hash('sha256', $title), 'classification' => $level->value, 'uploaded_by' => $by->id, 'created_by' => $by->id,
        'scan_status' => 'clean', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function consFollowUp(Incident $incident, User $by, string $body, Classification $level = Classification::Restricted): int
{
    return DB::table('incident_updates')->insertGetId([
        'ulid' => strtolower((string) Str::ulid()), 'incident_id' => $incident->id, 'state_id' => $incident->state_id, 'lga_id' => $incident->lga_id,
        'type' => 'follow_up', 'body' => $body, 'classification' => $level->value, 'created_by' => $by->id, 'created_at' => now()->subHour(),
    ]);
}

function consChild(string $table, Incident $incident, array $columns): int
{
    return DB::table($table)->insertGetId([
        'ulid' => strtolower((string) Str::ulid()), 'incident_id' => $incident->id, 'state_id' => $incident->state_id, 'lga_id' => $incident->lga_id,
        'created_at' => now(), 'updated_at' => now(), ...$columns,
    ]);
}

function consolidateVia(User $actor, Incident $duplicate, Incident $original, array $extra = []): TestResponse
{
    return W::step($actor, $duplicate, 'invalidate', ['reason' => 'duplicate', 'duplicate_of' => $original->ulid, 'consolidate' => true, ...$extra]);
}

test('consolidation applies every rule and invalidates the duplicate in one step', function () {
    $witness = Person::factory()->in($this->lga)->named('Ngozi', 'Eze')->create();
    $group = CriminalGroup::factory()->in($this->lga)->create();
    $agency = SecurityAgency::factory()->create()->id;
    $type = PropertyType::factory()->create();
    $flashpoint = Flashpoint::factory()->in($this->lga)->create();

    $evidence = consEvidence($this->duplicate, $this->chief);
    $sha = DB::table('incident_evidence')->where('id', $evidence)->value('sha256');
    $update = consFollowUp($this->duplicate, $this->chief, 'Witness says the gang fled north in a white bus.');
    consChild('incident_persons', $this->duplicate, ['person_id' => $witness->id, 'involvement' => 'witness', 'notes' => 'Saw the bus']);
    consChild('incident_persons', $this->duplicate, ['unidentified_description' => 'Driver of the bus', 'involvement' => 'suspect']);
    $ourGroup = consChild('incident_criminal_groups', $this->original, ['criminal_group_id' => $group->id, 'role' => 'claimed', 'notes' => 'Claimed responsibility']);
    consChild('incident_criminal_groups', $this->duplicate, ['criminal_group_id' => $group->id, 'role' => 'suspected', 'notes' => 'Known to operate here']);
    consChild('incident_agencies', $this->duplicate, ['security_agency_id' => $agency, 'role' => 'first_responder', 'notes' => 'Arrived 20 minutes later']);
    $ourProperty = consChild('incident_properties', $this->original, ['property_type_id' => $type->id, 'description' => 'Bus', 'quantity' => 1]);
    consChild('incident_properties', $this->duplicate, ['property_type_id' => $type->id, 'description' => 'White Toyota bus', 'quantity' => 1]);
    $casualty = consChild('incident_casualties', $this->duplicate, ['outcome' => 'abducted', 'group' => 'civilian', 'adult_male' => 3]);
    DB::table('flashpoint_incidents')->insert(['ulid' => strtolower((string) Str::ulid()), 'flashpoint_id' => $flashpoint->id, 'state_id' => $flashpoint->state_id, 'lga_id' => $flashpoint->lga_id, 'incident_id' => $this->duplicate->id, 'linked_by' => $this->sd->id, 'linked_at' => now()]);
    // The chief asked for access to a person in another state, citing this report.
    CrossFixtures::person($this->world->lgas['B1']);
    $request = app(JurisdictionContext::class)->actingAs($this->chief, fn () => CrossFixtures::request($this->chief))->id;
    DB::table('record_access_requests')->where('id', $request)->update(['incident_id' => $this->duplicate->id]);

    // Preview first: every item with its outcome.
    $preview = CrossFixtures::signIn($this->sd)->getJson("/incidents/{$this->duplicate->ulid}/consolidation?original={$this->original->ulid}")->assertOk()->json();
    expect($preview['allowed'])->toBeTrue()
        ->and(collect($preview['items'])->map(fn ($i) => "{$i['kind']}:{$i['outcome']}")->all())
        ->toBe(['evidence:move', 'update:copy', 'person:add', 'person:add', 'group:skip', 'agency:add', 'property:skip', 'flashpoint:add', 'access_request:repoint'])
        ->and($preview['casualties']['duplicate'])->toHaveCount(1)
        ->and($preview['casualties']['original'])->toHaveCount(0);

    consolidateVia($this->sd, $this->duplicate, $this->original)->assertSessionHasNoErrors();

    $ref = W::fresh($this->duplicate)->reference_no;
    // Evidence moved: same row, ULID, hash; custody records the move.
    $moved = DB::table('incident_evidence')->where('id', $evidence)->first();
    expect($moved->incident_id)->toBe($this->original->id)
        ->and($moved->sha256)->toBe($sha)
        ->and($moved->consolidated_from_incident_id)->toBe($this->duplicate->id)
        ->and(Activity::query()->where('subject_type', IncidentEvidence::class)->where('subject_id', $evidence)->where('event', 'consolidated')->exists())->toBeTrue();

    // Follow-up copied with attribution; the original stays on the duplicate.
    $copy = DB::table('incident_updates')->where('copied_from_update_id', $update)->first();
    expect($copy->incident_id)->toBe($this->original->id)
        ->and($copy->body)->toBe('Witness says the gang fled north in a white bus.')
        ->and($copy->created_by)->toBe($this->sd->id)
        ->and(DB::table('incident_updates')->where('id', $update)->value('incident_id'))->toBe($this->duplicate->id);
    $timeline = CrossFixtures::signIn($this->sd)->get("/incidents/{$this->original->ulid}?tab=timeline")->viewData('page')['props']['timeline'];
    $copied = collect($timeline)->firstWhere('body', 'Witness says the gang fled north in a white bus.');
    expect($copied['origin'])->toStartWith("Originally recorded on {$ref} by {$this->chief->name} at ")
        ->and(collect($timeline)->pluck('label'))->toContain("Content consolidated from {$ref}");

    // Persons: witness added, unidentified added with its origin.
    $persons = IncidentPerson::query()->where('incident_id', $this->original->id)->get();
    expect($persons->pluck('person_id')->filter()->values()->all())->toBe([$witness->id])
        ->and($persons->firstWhere('person_id', null)?->unidentified_description)->toBe("Driver of the bus (from {$ref})");

    // Group and property skipped, notes appended and labelled; agency added.
    expect(DB::table('incident_criminal_groups')->where('id', $ourGroup)->value('notes'))->toBe("Claimed responsibility\n\n[From {$ref}] Known to operate here")
        ->and(DB::table('incident_criminal_groups')->where('incident_id', $this->original->id)->count())->toBe(1)
        ->and(DB::table('incident_properties')->where('id', $ourProperty)->value('description'))->toBe("Bus\n\n[From {$ref}] White Toyota bus")
        ->and(DB::table('incident_agencies')->where('incident_id', $this->original->id)->count())->toBe(1);

    // Casualties untouched; flashpoint linked; access request repointed.
    expect(DB::table('incident_casualties')->where('id', $casualty)->value('incident_id'))->toBe($this->duplicate->id)
        ->and(DB::table('incident_casualties')->where('incident_id', $this->original->id)->count())->toBe(0)
        ->and(DB::table('flashpoint_incidents')->where('incident_id', $this->original->id)->where('flashpoint_id', $flashpoint->id)->exists())->toBeTrue()
        ->and(DB::table('record_access_requests')->where('id', $request)->value('incident_id'))->toBe($this->original->id);

    // The duplicate is invalid and locked; the consolidation is recorded and chained.
    $dup = W::fresh($this->duplicate);
    $record = IncidentConsolidation::query()->withoutGlobalScopes()->sole();
    expect($dup->status)->toBe(IncidentStatus::Invalid)
        ->and($dup->duplicate_of_incident_id)->toBe($this->original->id)
        ->and(collect($record->manifest['items'])->pluck('outcome')->countBy()->all())->toMatchArray(['move' => 1, 'copy' => 1, 'add' => 4, 'skip' => 2, 'repoint' => 1])
        ->and(Activity::query()->where('subject_id', $this->original->id)->where('event', 'consolidated_from')->exists())->toBeTrue()
        ->and(Activity::query()->where('subject_id', $this->duplicate->id)->where('event', 'consolidated_into')->exists())->toBeTrue()
        ->and(AuditChain::verify('incident_consolidations')['ok'])->toBeTrue()
        ->and(AuditChain::verify('incident_updates')['ok'])->toBeTrue();

    Notification::assertSentTo($this->chief, IncidentNotification::class, fn ($n) => $n->event === 'consolidated' && $n->incidentUlid === $this->original->ulid);
});

test('evidence below the original\'s classification is raised to match (logged)', function () {
    DB::table('incidents')->where('id', $this->original->id)->update(['classification' => 'confidential']);
    $evidence = consEvidence($this->duplicate, $this->chief);

    consolidateVia($this->sd, $this->duplicate, W::fresh($this->original))->assertSessionHasNoErrors();

    expect(DB::table('incident_evidence')->where('id', $evidence)->value('classification'))->toBe('confidential')
        ->and(Activity::query()->where('subject_id', $evidence)->where('subject_type', IncidentEvidence::class)->where('event', 'classification_changed')->value('properties')->toArray())
        ->toMatchArray(['old' => 'restricted', 'new' => 'confidential']);
});

test('items above the consolidator\'s clearance are listed and left in place', function () {
    // A lowered-clearance consolidator: every role that may invalidate today
    // has secret clearance, so a deputy (confidential) is given the right.
    $deputy = $this->world->users['deputy_state_director'];
    $deputy->givePermissionTo('incidents.invalidate');
    $secret = consEvidence($this->duplicate, $this->sd, Classification::Secret, 'Informant photo');
    $secretUpdate = consFollowUp($this->duplicate, $this->sd, 'Source identity: ...', Classification::Secret);
    $open = consEvidence($this->duplicate, $this->chief);

    $preview = CrossFixtures::signIn($deputy)->getJson("/incidents/{$this->duplicate->ulid}/consolidation?original={$this->original->ulid}")->json();
    $hidden = collect($preview['items'])->where('outcome', 'not_consolidated')->values();
    expect($hidden)->toHaveCount(2)
        ->and($hidden->pluck('detail')->unique()->all())->toBe(['above your clearance'])
        ->and($hidden->pluck('label')->filter()->all())->toBe([])
        ->and(json_encode($preview))->not->toContain('Informant')
        ->and(json_encode($preview))->not->toContain('Source identity');

    consolidateVia($deputy, $this->duplicate, $this->original)->assertSessionHasNoErrors();

    expect(DB::table('incident_evidence')->where('id', $secret)->value('incident_id'))->toBe($this->duplicate->id)
        ->and(DB::table('incident_evidence')->where('id', $open)->value('incident_id'))->toBe($this->original->id)
        ->and(DB::table('incident_updates')->where('copied_from_update_id', $secretUpdate)->exists())->toBeFalse()
        ->and(W::fresh($this->duplicate)->status)->toBe(IncidentStatus::Invalid);
});

test('a failure part-way changes nothing', function () {
    $evidence = consEvidence($this->duplicate, $this->chief);
    consFollowUp($this->duplicate, $this->chief, 'A follow-up');
    $owner = DB::connection('pgsql_migrations');
    $owner->unprepared(<<<'SQL'
        CREATE FUNCTION zz_fail_consolidation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'injected failure'; END $$;
        CREATE TRIGGER zz_fail_consolidation BEFORE INSERT ON incident_consolidations FOR EACH ROW EXECUTE FUNCTION zz_fail_consolidation();
        SQL);

    try {
        expect(fn () => consolidateVia($this->sd, $this->duplicate, $this->original)->assertStatus(500))->not->toThrow(Throwable::class);
    } finally {
        $owner->unprepared('DROP TRIGGER zz_fail_consolidation ON incident_consolidations; DROP FUNCTION zz_fail_consolidation();');
    }

    expect(W::fresh($this->duplicate)->status)->toBe(IncidentStatus::Submitted)
        ->and(DB::table('incident_evidence')->where('id', $evidence)->value('incident_id'))->toBe($this->duplicate->id)
        ->and(DB::table('incident_updates')->where('incident_id', $this->original->id)->count())->toBe(0)
        ->and(Activity::query()->where('event', 'consolidated')->exists())->toBeFalse();
});

test('authorisation: invalidate rights on the duplicate and edit rights on the original', function () {
    // An LGA chief cannot invalidate.
    consolidateVia($this->chief, $this->duplicate, $this->original)->assertForbidden();

    // A closed original cannot receive content.
    $closed = W::at(W::draft($this->lga, $this->chief), IncidentStatus::Closed);
    consolidateVia($this->sd, $this->duplicate, $closed)->assertSessionHasErrors('duplicate_of');

    // A verified original needs an amendment reason.
    $verified = W::at(W::draft($this->lga, $this->chief), IncidentStatus::Verified);
    consolidateVia($this->sd, $this->duplicate, $verified)->assertSessionHasErrors('amendment_reason');
    expect(W::fresh($this->duplicate)->status)->toBe(IncidentStatus::Submitted);

    consolidateVia($this->sd, $this->duplicate, $verified, ['amendment_reason' => 'Adding the duplicate report\'s photo and witness.'])->assertSessionHasNoErrors();
    expect(Activity::query()->where('subject_id', $verified->id)->where('event', 'amended')->exists())->toBeTrue();

    // Another state's original is not found through the scopes.
    $other = W::at(W::draft($this->lga, $this->chief), IncidentStatus::Submitted);
    $elsewhere = W::at(W::draft($this->world->lgas['B1'], CrossFixtures::chiefOf($this->world->lgas['B1'])), IncidentStatus::Submitted);
    consolidateVia($this->sd, $other, $elsewhere)->assertSessionHasErrors('duplicate_of');
});

test('without consolidation, invalidating as a duplicate is unchanged', function () {
    $evidence = consEvidence($this->duplicate, $this->chief);

    W::step($this->sd, $this->duplicate, 'invalidate', ['reason' => 'duplicate', 'duplicate_of' => $this->original->ulid])->assertSessionHasNoErrors();

    expect(DB::table('incident_evidence')->where('id', $evidence)->value('incident_id'))->toBe($this->duplicate->id)
        ->and(IncidentConsolidation::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('statistics count the original only after consolidation', function () {
    consChild('incident_casualties', $this->duplicate, ['outcome' => 'abducted', 'group' => 'civilian', 'adult_male' => 3]);
    consChild('incident_casualties', $this->original, ['outcome' => 'abducted', 'group' => 'civilian', 'adult_male' => 3]);
    $figures = fn () => app(JurisdictionContext::class)->actingAs($this->sd, fn () => app(IncidentFigures::class)->forState($this->sd, $this->world->states['A1']));

    expect($figures()['awaiting']['count'])->toBe(2);

    consolidateVia($this->sd, $this->duplicate, $this->original)->assertSessionHasNoErrors();

    $after = $figures();
    expect($after['awaiting']['count'])->toBe(1)
        ->and(collect($after['casualtiesByOutcome'])->sum(fn ($row) => $row['total'] ?? $row['count'] ?? 0))->toBe(3);
});

test('incident_consolidations is append-only', function () {
    consolidateVia($this->sd, $this->duplicate, $this->original)->assertSessionHasNoErrors();
    $id = DB::table('incident_consolidations')->value('id');

    expect(fn () => DB::transaction(fn () => DB::table('incident_consolidations')->where('id', $id)->update(['manifest' => '{}'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('incident_consolidations')->where('id', $id)->delete()))->toThrow(QueryException::class)
        ->and(DB::selectOne("SELECT has_table_privilege('buffalo_app', 'incident_consolidations', 'UPDATE') AS u")->u)->toBeFalse();
});

test('the evidence tab marks consolidated items', function () {
    consEvidence($this->duplicate, $this->chief);
    consolidateVia($this->sd, $this->duplicate, $this->original)->assertSessionHasNoErrors();
    $ref = W::fresh($this->duplicate)->reference_no;

    $props = CrossFixtures::signIn($this->sd)->get("/incidents/{$this->original->ulid}?tab=evidence")->viewData('page')['props'];
    expect(json_encode($props))->toContain('"consolidatedFrom":"'.str_replace('/', '\/', (string) $ref).'"');
});
