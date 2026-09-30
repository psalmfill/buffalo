<?php

use App\Enums\Classification;
use App\Models\Organization;
use App\Models\OrganizationContact;
use App\Models\OrganizationSite;
use App\Models\Person;
use App\Models\RecordAccessLog;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(function () {
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->organization = Organization::factory()->create(['name' => 'Niger Delta Petroleum Company']);
    $this->site = OrganizationSite::factory()->of($this->organization)->in($this->world->lgas['A1a'])->create(['name' => 'Bonny Export Terminal']);
    $this->chief = $this->world->users['lga_chief'];
});

function contactPayload(array $overrides = []): array
{
    return [
        'name' => 'Emeka Nwosu',
        'position' => 'Security Manager',
        'phone' => '0803 555 0100',
        'email' => 'Emeka.Nwosu@NDPC.example',
        'is_management' => true,
        'is_security_contact' => true,
        'is_primary' => true,
        ...$overrides,
    ];
}

function addContact(array $overrides = []): OrganizationContact
{
    CrossFixtures::signIn(test()->chief)->post('/organization-sites/'.test()->site->ulid.'/contacts', contactPayload($overrides))->assertSessionHasNoErrors();
    CrossFixtures::system();

    return OrganizationContact::query()->latest('id')->firstOrFail();
}

function contactIn(OrganizationSite $site, string $name): OrganizationContact
{
    $contact = new OrganizationContact;
    $contact->forceFill(['organization_site_id' => $site->id, 'state_id' => $site->state_id, 'lga_id' => $site->lga_id, 'name' => $name, 'position' => 'Manager'])->save();

    return $contact;
}

test('phone and email are stored as ciphertext, normalised, and never logged', function () {
    $contact = addContact();

    $raw = DB::table('organization_contacts')->where('id', $contact->id)->first();
    expect($raw->phone)->toStartWith('nacl:')->not->toContain('555')
        ->and($raw->email)->toStartWith('nacl:')->not->toContain('ndpc')
        ->and($contact->phone)->toBe('+2348035550100')
        ->and($contact->email)->toBe('emeka.nwosu@ndpc.example');

    // The database refuses plaintext.
    expect(fn () => DB::transaction(fn () => DB::table('organization_contacts')->where('id', $contact->id)->update(['phone' => '+2348035550100'])))
        ->toThrow(QueryException::class, 'organization_contacts_phone_encrypted');

    // Never in the activity log; the named event says which fields only.
    $activity = Activity::query()->where('subject_type', $contact->getMorphClass())->where('subject_id', $contact->id)->get();
    expect($activity->pluck('event'))->toContain('protected_fields_changed');
    expect(json_encode($activity->pluck('properties')))->not->toContain('555')->not->toContain('ndpc.example');
});

test('lookup by phone written differently, or by email in any case, finds the contact within jurisdiction; each view is logged', function () {
    $contact = addContact();

    foreach (['+234 803 555 0100', '08035550100', '234-803-555-0100'] as $phone) {
        CrossFixtures::signIn($this->chief)->get('/organizations?q='.urlencode($phone))
            ->assertInertia(fn (Assert $page) => $page->where('lookup', 'phone')->has('contacts', 1)
                ->where('contacts.0.ulid', $contact->ulid)
                ->where('contacts.0.site.name', 'Bonny Export Terminal'));
    }

    CrossFixtures::signIn($this->chief)->get('/organizations?q='.urlencode('EMEKA.NWOSU@ndpc.EXAMPLE'))
        ->assertInertia(fn (Assert $page) => $page->where('lookup', 'email')->has('contacts', 1));

    expect(RecordAccessLog::query()->where('subject_id', $contact->id)->where('subject_type', $contact->getMorphClass())->where('context', 'lookup')->count())->toBe(4);

    // Another state: nothing.
    CrossFixtures::signIn($this->world->users['ddg_zone_b'])->get('/organizations?q=08035550100')
        ->assertInertia(fn (Assert $page) => $page->has('contacts', 0));
});

test('contacts follow their own jurisdiction and their site\'s classification', function () {
    contactIn($this->site, 'Own LGA');
    contactIn(OrganizationSite::factory()->of($this->organization)->in($this->world->lgas['B1'])->create(), 'Other zone');
    contactIn(OrganizationSite::factory()->of($this->organization)->in($this->world->lgas['A1a'])->classified(Classification::Confidential)->create(), 'Confidential site');

    $visible = fn (string $who) => app(JurisdictionContext::class)->actingAs($this->world->users[$who], fn () => OrganizationContact::query()->orderBy('name')->pluck('name')->all());

    expect($visible('lga_chief'))->toBe(['Own LGA'])
        ->and($visible('state_director'))->toBe(['Confidential site', 'Own LGA'])
        ->and($visible('ddg_zone_b'))->toBe(['Other zone'])
        ->and($visible('director_general'))->toBe(['Confidential site', 'Other zone', 'Own LGA'])
        ->and($visible('super_admin'))->toBe([]);

    // Not visible through the organization or site relationship alone.
    $otherSite = OrganizationSite::query()->where('lga_id', $this->world->lgas['B1']->id)->sole();
    expect(app(JurisdictionContext::class)->actingAs($this->chief, fn () => $otherSite->contacts()->count()))->toBe(0);
});

test('every contact shown on the site page is logged; contact details appear on no other page', function () {
    $contact = addContact();

    CrossFixtures::signIn($this->chief)->get("/organization-sites/{$this->site->ulid}")
        ->assertInertia(fn (Assert $page) => $page->has('contacts', 1)->where('contacts.0.phone', '+2348035550100'));
    expect(RecordAccessLog::query()->where('subject_id', $contact->id)->where('subject_type', $contact->getMorphClass())->where('context', 'site_page')->count())->toBe(1);

    foreach (["/organizations/{$this->organization->ulid}", '/organizations', "/lgas/{$this->world->lgas['A1a']->ulid}?tab=organizations", '/dashboard', "/organization-sites/{$this->site->ulid}?tab=history"] as $url) {
        $html = CrossFixtures::signIn($this->chief)->get($url)->assertOk()->getContent();
        expect($html)->not->toContain('8035550100')->not->toContain('ndpc.example');
    }
});

test('one primary contact per site', function () {
    addContact();

    CrossFixtures::signIn($this->chief)->post("/organization-sites/{$this->site->ulid}/contacts", contactPayload(['name' => 'Second', 'phone' => null, 'email' => null]))
        ->assertSessionHasErrors(['is_primary' => 'This site already has a primary contact.']);
    CrossFixtures::signIn($this->chief)->post("/organization-sites/{$this->site->ulid}/contacts", contactPayload(['name' => 'Second', 'phone' => null, 'email' => null, 'is_primary' => false]))
        ->assertSessionHasNoErrors();

    $second = OrganizationContact::query()->where('name', 'Second')->sole();
    expect(fn () => DB::transaction(fn () => $second->forceFill(['is_primary' => true])->save()))->toThrow(QueryException::class, 'organization_contacts_one_primary');
});

test('the contact\'s place always equals its site\'s', function () {
    $contact = contactIn($this->site, 'Placed');

    expect(fn () => DB::transaction(fn () => DB::table('organization_contacts')->where('id', $contact->id)->update(['lga_id' => $this->world->lgas['A1b']->id])))
        ->toThrow(QueryException::class);
});

test('linking a contact to a person needs link on the person; the link is shown only to those who can see the person', function () {
    $mine = Person::factory()->in($this->world->lgas['A1a'])->create();
    $elsewhere = Person::factory()->in($this->world->lgas['A1b'])->create();
    $secret = Person::factory()->in($this->world->lgas['A1a'])->classified(Classification::Secret)->create();

    CrossFixtures::signIn($this->chief)->post("/organization-sites/{$this->site->ulid}/contacts", contactPayload(['person' => $elsewhere->ulid]))
        ->assertSessionHasErrors(['person' => 'Choose a person you may link to.']);
    CrossFixtures::signIn($this->chief)->post("/organization-sites/{$this->site->ulid}/contacts", contactPayload(['person' => $secret->ulid]))
        ->assertSessionHasErrors('person');

    $contact = addContact(['person' => $mine->ulid]);
    expect($contact->person_id)->toBe($mine->id);
    CrossFixtures::signIn($this->chief)->get("/organization-sites/{$this->site->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('contacts.0.person.ulid', $mine->ulid)->where('contacts.0.person_hidden', false));

    // A person the viewer cannot see: flagged, never named; kept on edit.
    $contact->forceFill(['person_id' => $secret->id])->save();
    CrossFixtures::signIn($this->chief)->get("/organization-sites/{$this->site->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('contacts.0.person', null)->where('contacts.0.person_hidden', true))
        ->assertDontSee($secret->last_name);
    $edit = contactPayload(['position' => 'Head of Security']);
    CrossFixtures::signIn($this->chief)->put("/organization-contacts/{$contact->ulid}", $edit)->assertSessionHasNoErrors();
    expect($contact->fresh()->person_id)->toBe($secret->id);

    // The person link event carries ULIDs only.
    $event = Activity::query()->where('event', 'person_link_changed')->latest('id')->first();
    expect(json_encode($event?->properties))->not->toContain($mine->last_name);
});

test('only those who can manage the site edit or delete its contacts', function () {
    $contact = addContact();

    expect($this->world->users['state_director']->can('update', $contact))->toBeTrue()
        ->and($this->world->users['ddg_zone_b']->can('update', $contact))->toBeFalse()
        ->and($this->world->users['super_admin']->can('view', $contact))->toBeFalse();

    CrossFixtures::signIn($this->world->users['ddg_zone_b'])->put("/organization-contacts/{$contact->ulid}", contactPayload())->assertNotFound();
    CrossFixtures::signIn($this->chief)->delete("/organization-contacts/{$contact->ulid}", ['reason' => 'Left the company'])->assertRedirect();
    expect(OrganizationContact::withTrashed()->find($contact->id)?->trashed())->toBeTrue();
});

test('no contact value reaches the application log', function () {
    Log::spy();

    addContact();
    CrossFixtures::signIn($this->chief)->get('/organizations?q=08035550100');

    Log::shouldNotHaveReceived('info', fn (...$args) => str_contains(json_encode($args), '8035550100'));
    Log::shouldNotHaveReceived('debug', fn (...$args) => str_contains(json_encode($args), '8035550100'));
});
