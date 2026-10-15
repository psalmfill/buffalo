<?php

use App\Enums\Classification;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\IncidentPerson;
use App\Models\Person;
use App\Models\PersonAlias;
use App\Models\PersonOfInterest;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Persons\Merge\MergeBlocked;
use App\Support\Persons\Merge\MergeChoices;
use App\Support\Persons\PersonSearch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Persons\MergeFixtures;

/*
| Phase 10A.4 / 10A.6: every merge rule, and the manifest that undoes it.
*/

beforeEach(function () {
    $this->world = JurisdictionWorld::build();
    $this->sd = $this->world->users['state_director'];
    CrossFixtures::system();
});

/** @return list<string> */
function aliasesOf(Person $person): array
{
    return DB::table('person_aliases')->where('person_id', $person->id)->whereNull('deleted_at')->orderBy('alias')->pluck('alias')->all();
}

test('a merge applies every rule, and undo restores every row exactly', function () {
    ['survivor' => $survivor, 'source' => $source, 'ids' => $ids] = MergeFixtures::rich($this->world);
    $before = MergeFixtures::rows($ids);
    $personsBefore = MergeFixtures::rows(['persons' => [$survivor->id, $source->id]]);
    $sourcePhotos = DB::table('media')->where('model_id', $source->id)->orderBy('id')->pluck('id')->all();

    $merge = MergeFixtures::merge($this->sd, $survivor, $source, new MergeChoices(
        fields: ['last_name' => 'survivor', 'occupation' => 'source'],
        photo: 'source',
        poiStatus: 'source',
    ));

    $s = MergeFixtures::reload($survivor);
    $src = MergeFixtures::reload($source);

    // Scalar fields: chosen, or the only value; the source untouched but merged.
    expect($s->last_name)->toBe('Musa')
        ->and($s->occupation)->toBe('Farmer')
        ->and($s->phone)->toBe('+2348030000001')
        ->and($s->date_of_birth)->toBe('1985-03-04')
        ->and($s->address)->toBe('12 Market Road')
        ->and($s->lga_id)->toBe($this->world->lgas['A1a']->id)
        ->and($src->trashed())->toBeTrue()
        ->and($src->merged_into_person_id)->toBe($s->id)
        ->and($src->last_name)->toBe('Mussa')
        ->and($merge->field_choices)->toBe(['last_name' => 'survivor', 'occupation' => 'source', 'place' => 'survivor', 'poi_status' => 'source', 'photo' => 'source']);

    // Losing name and new aliases on the survivor; the duplicate alias stays with the source.
    expect(aliasesOf($s))->toBe(['Big Garba', 'Garba Mussa', 'Mallam G'])
        ->and(aliasesOf($src))->toBe(['Big Garba']);

    // Roles: union, active if active on either.
    expect(DB::table('person_roles')->join('person_role_types', 'person_role_types.id', '=', 'person_roles.person_role_type_id')->where('person_id', $s->id)->orderBy('code')->get(['code', 'person_roles.is_active'])->map(fn ($r) => $r->code.':'.($r->is_active ? 'on' : 'off'))->all())
        ->toBe(['community_leader:on', 'political_leader:on']);

    // Associations: the link between the two dropped, the duplicate pair combined, the rest repointed.
    $links = DB::table('person_associations')->whereNull('deleted_at')->where(fn ($q) => $q->where('person_id', $s->id)->orWhere('associated_person_id', $s->id))->orderBy('id')->get();
    expect($links)->toHaveCount(2)
        ->and($links[0]->notes)->toBe("Business partner\n\n[From merged record {$src->ulid}] Seen together")
        ->and(DB::table('person_associations')->whereNull('deleted_at')->where(fn ($q) => $q->where('person_id', $src->id)->orWhere('associated_person_id', $src->id))->count())->toBe(0);

    // POI: combined into the survivor's row.
    $poi = DB::table('persons_of_interest')->where('person_id', $s->id)->whereNull('deleted_at')->first();
    $oldPoi = DB::table('persons_of_interest')->where('person_id', $src->id)->first();
    expect($poi->risk_level)->toBe('high')
        ->and($poi->status)->toBe('under_surveillance')
        ->and(json_decode($poi->offence_categories, true))->toBe(['armed_robbery', 'kidnapping'])
        // Rich text (Phase 11B): one labelled block per record, with plain text copies.
        ->and($poi->known_offences)->toBe("<h4>Surviving record {$s->ulid}</h4>Kidnapping on the highway.<h4>From merged record {$src->ulid}</h4>Armed robbery in 2024.")
        ->and($poi->known_offences_text)->toBe("Surviving record {$s->ulid}\n\nKidnapping on the highway.\n\nFrom merged record {$src->ulid}\n\nArmed robbery in 2024.")
        ->and($poi->modus_operandi)->toBe("<h4>From merged record {$src->ulid}</h4>Night raids.")
        ->and($poi->review_due_at)->toBe('2027-01-15')
        ->and($poi->last_sighted_at)->not->toBeNull()
        ->and($oldPoi->deleted_at)->not->toBeNull()
        ->and($oldPoi->merged_into_poi_id)->toBe($poi->id);

    // Offices repointed; memberships combined with the stronger status and earliest date.
    expect(DB::table('political_offices')->where('person_id', $s->id)->count())->toBe(1);
    $membership = DB::table('criminal_group_members')->where('person_id', $s->id)->whereNull('deleted_at')->orderBy('id')->get();
    expect($membership)->toHaveCount(2)
        ->and($membership[0]->membership_status)->toBe('confirmed')
        ->and($membership[0]->since)->toBe('2020-05-05')
        ->and($membership[0]->notes)->toContain("[From merged record {$src->ulid}] Notes on Garba Mussa");

    // Incident persons: same incident and involvement combined; the closed incident's row repointed.
    $incidentRows = DB::table('incident_persons')->where('person_id', $s->id)->whereNull('deleted_at')->orderBy('id')->get(['incident_id', 'involvement', 'notes']);
    expect($incidentRows)->toHaveCount(3)
        ->and($incidentRows[0]->notes)->toBe("Seen at the scene\n\n[From merged record {$src->ulid}] Fled north");

    // Community, religious and organization links; photos with the chosen current.
    expect(DB::table('communities')->where('traditional_ruler_id', $s->id)->count())->toBe(1)
        ->and(DB::table('religious_institutions')->where('leader_person_id', $s->id)->count())->toBe(1)
        ->and(DB::table('organization_contacts')->where('person_id', $s->id)->count())->toBe(1)
        ->and(DB::table('media')->where('model_id', $s->id)->count())->toBe(3)
        ->and(app(JurisdictionContext::class)->runAsSystem('test', fn () => $s->currentPhoto()?->id))->toBe(end($sourcePhotos));

    // A row added to the survivor after the merge stays with it after the undo.
    $later = PersonAlias::query()->forceCreate(['person_id' => $s->id, 'alias' => 'Added later']);

    MergeFixtures::undo($this->sd, $merge);

    $after = MergeFixtures::rows($ids);
    expect($after)->toEqual($before);

    $personsAfter = MergeFixtures::rows(['persons' => [$survivor->id, $source->id]]);
    foreach ([$survivor->id, $source->id] as $id) {
        foreach (['date_of_birth', 'nin', 'phone', 'address', 'updated_by'] as $volatile) {
            // Ciphertext is re-generated on save (random nonce); compared decrypted below.
            unset($personsBefore['persons'][$id][$volatile], $personsAfter['persons'][$id][$volatile]);
        }
    }
    expect($personsAfter)->toEqual($personsBefore);

    $restored = MergeFixtures::reload($survivor);
    expect([$restored->phone, $restored->date_of_birth, $restored->address, $restored->occupation])->toBe(['+2348030000001', null, null, 'Trader'])
        ->and(aliasesOf($restored))->toBe(['Added later', 'Big Garba'])
        ->and($later->fresh()?->person_id)->toBe($survivor->id)
        ->and(MergeFixtures::reload($source)->trashed())->toBeFalse()
        ->and(MergeFixtures::reload($source)->merged_into_person_id)->toBeNull();
});

test('two different NINs are a hard stop', function () {
    $a = Person::factory()->in($this->world->lgas['A1a'])->create(['nin' => '11111111111']);
    $b = Person::factory()->in($this->world->lgas['A1a'])->create(['nin' => '22222222222']);

    expect(fn () => MergeFixtures::merge($this->sd, $a, $b, new MergeChoices(fields: ['nin' => 'source'])))
        ->toThrow(MergeBlocked::class, 'different NINs')
        ->and(MergeFixtures::reload($b)->trashed())->toBeFalse();
});

test('different dates of birth need an explicit confirmation', function () {
    $a = Person::factory()->in($this->world->lgas['A1a'])->create(['date_of_birth' => '1980-01-01']);
    $b = Person::factory()->in($this->world->lgas['A1a'])->create(['date_of_birth' => '1981-01-01']);

    expect(fn () => MergeFixtures::merge($this->sd, $a, $b))->toThrow(MergeBlocked::class, 'dates of birth differ');

    MergeFixtures::merge($this->sd, $a, $b, new MergeChoices(fields: ['date_of_birth' => 'source'], confirmDob: true));
    expect(MergeFixtures::reload($a)->date_of_birth)->toBe('1981-01-01');
});

test('deceased is kept when either record is deceased, with a confirmation', function () {
    $a = Person::factory()->in($this->world->lgas['A1a'])->create(['is_deceased' => false]);
    $b = Person::factory()->in($this->world->lgas['A1a'])->create(['is_deceased' => true]);

    expect(fn () => MergeFixtures::merge($this->sd, $a, $b))->toThrow(MergeBlocked::class, 'deceased');

    MergeFixtures::merge($this->sd, $a, $b, new MergeChoices(confirmDeceased: true));
    expect(MergeFixtures::reload($a)->is_deceased)->toBeTrue();
});

test('encrypted fields are re-encrypted and the source\'s NIN and phone find the survivor', function () {
    $a = Person::factory()->in($this->world->lgas['A1a'])->named('Ada', 'Obi')->create();
    $b = Person::factory()->in($this->world->lgas['A1a'])->named('Adaeze', 'Obi')->create(['nin' => '33333333333', 'phone' => '08035550000']);
    $rawSource = DB::table('persons')->where('id', $b->id)->first(['nin', 'phone']);

    MergeFixtures::merge($this->sd, $a, $b);

    $raw = DB::table('persons')->where('id', $a->id)->first(['nin', 'phone']);
    $found = app(JurisdictionContext::class)->actingAs($this->sd, fn () => [
        Person::query()->whereBlind('nin', 'nin_bidx', '33333333333')->pluck('id')->all(),
        Person::query()->whereBlind('phone', 'phone_bidx', '+2348035550000')->pluck('id')->all(),
    ]);

    expect($raw->nin)->not->toBe($rawSource->nin)
        ->and($raw->nin)->not->toContain('33333333333')
        ->and($raw->phone)->not->toBe($rawSource->phone)
        ->and($found)->toBe([[$a->id], [$a->id]])
        ->and(MergeFixtures::reload($a)->nin)->toBe('33333333333');
});

test('names not chosen become aliases, and searching the old name finds the survivor', function () {
    $a = Person::factory()->in($this->world->lgas['A1a'])->named('Chukwuemeka', 'Nwosu')->create();
    $b = Person::factory()->in($this->world->lgas['A1a'])->named('Emeka', 'Nwosu')->create();

    MergeFixtures::merge($this->sd, $a, $b, new MergeChoices(fields: ['first_name' => 'survivor']));

    $found = app(JurisdictionContext::class)->actingAs($this->sd, fn () => PersonSearch::withThresholds(0.3, 0.5, fn () => PersonSearch::byName(Person::query(), 'Emeka Nwosu')->pluck('persons.id')->all()));

    expect(aliasesOf($a))->toBe(['Emeka Nwosu'])
        ->and($found)->toBe([$a->id]);
});

test('the survivor takes the higher classification', function () {
    $a = Person::factory()->in($this->world->lgas['A1a'])->classified(Classification::Restricted)->create();
    $b = Person::factory()->in($this->world->lgas['A1a'])->classified(Classification::Secret)->create();

    MergeFixtures::merge($this->sd, $a, $b);

    expect(MergeFixtures::reload($a)->classificationLevel())->toBe(Classification::Secret);
});

test('only one POI: it moves to the survivor with the survivor\'s place', function () {
    $a = Person::factory()->in($this->world->lgas['A1a'])->create();
    $b = Person::factory()->in($this->world->lgas['A1b'])->create();
    $poi = PersonOfInterest::factory()->forPerson($b)->create();

    MergeFixtures::merge($this->sd, $a, $b);

    $row = DB::table('persons_of_interest')->where('id', $poi->id)->first();
    expect([$row->person_id, $row->lga_id, $row->deleted_at])->toBe([$a->id, $this->world->lgas['A1a']->id, null]);
});

test('the executor may give the survivor the source\'s LGA (same state)', function () {
    $a = Person::factory()->in($this->world->lgas['A1a'])->create();
    $b = Person::factory()->in($this->world->lgas['A1b'])->create();
    $poi = PersonOfInterest::factory()->forPerson($a)->create();

    MergeFixtures::merge($this->sd, $a, $b, new MergeChoices(place: 'source'));

    expect(MergeFixtures::reload($a)->lga_id)->toBe($this->world->lgas['A1b']->id)
        ->and(DB::table('persons_of_interest')->where('id', $poi->id)->value('lga_id'))->toBe($this->world->lgas['A1b']->id);
});

test('the locked-incident exception applies to merges only', function () {
    $person = Person::factory()->in($this->world->lgas['A1a'])->create();
    $other = Person::factory()->in($this->world->lgas['A1a'])->create();
    $incident = Incident::factory()->in($this->world->lgas['A1a'])->create(['status' => IncidentStatus::Submitted]);
    $row = IncidentPerson::query()->forceCreate(['incident_id' => $incident->id, 'state_id' => $incident->state_id, 'lga_id' => $incident->lga_id, 'person_id' => $person->id, 'involvement' => 'suspect']);
    DB::table('incidents')->where('id', $incident->id)->update(['status' => 'closed']);

    $attempt = fn (array $values, ?string $merge = null) => DB::transaction(function () use ($row, $values, $merge) {
        if ($merge !== null) {
            DB::statement("SELECT set_config('buffalo.person_merge', ?, true)", [$merge]);
        }
        DB::table('incident_persons')->where('id', $row->id)->update($values);
    });

    // A normal edit of a closed incident's persons is still rejected...
    expect(fn () => $attempt(['person_id' => $other->id]))->toThrow(QueryException::class, 'locked')
        // ...and under a merge, only the person, notes and deletion may change.
        ->and(fn () => $attempt(['involvement' => 'victim'], 'merge-ulid'))->toThrow(QueryException::class, 'locked')
        ->and(fn () => $attempt(['incident_id' => Incident::factory()->in($this->world->lgas['A1a'])->create()->id], 'merge-ulid'))->toThrow(QueryException::class, 'locked')
        ->and(fn () => DB::transaction(fn () => DB::table('incident_persons')->insert(['ulid' => 'x', 'incident_id' => $incident->id, 'state_id' => $incident->state_id, 'lga_id' => $incident->lga_id, 'person_id' => $other->id, 'involvement' => 'victim', 'created_at' => now(), 'updated_at' => now()])))->toThrow(QueryException::class, 'locked');

    $attempt(['person_id' => $other->id, 'notes' => 'merged'], 'merge-ulid');
    // (set_config(..., true) lasts until the end of the top-level
    // transaction: in the application that is the merge's own; here the
    // test's, so it is cleared explicitly.)
    DB::statement("SELECT set_config('buffalo.person_merge', '', true)");

    expect(DB::table('incident_persons')->where('id', $row->id)->value('person_id'))->toBe($other->id)
        ->and(fn () => $attempt(['person_id' => $person->id]))->toThrow(QueryException::class, 'locked');
});
