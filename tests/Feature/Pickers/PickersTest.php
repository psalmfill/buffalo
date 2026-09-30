<?php

use App\Enums\Classification;
use App\Enums\GrantScope;
use App\Models\AgencyFormation;
use App\Models\Community;
use App\Models\CriminalGroup;
use App\Models\Organization;
use App\Models\OrganizationSector;
use App\Models\OrganizationSite;
use App\Models\Person;
use App\Models\SecurityAgency;
use App\Models\User;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(function () {
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->chief = $this->world->users['lga_chief'];
});

function pick(User $user, string $entity, array $body = []): array
{
    return CrossFixtures::signIn($user)->postJson("/pickers/{$entity}", $body)->assertOk()->json('results');
}

function labels(array $results): array
{
    $labels = array_column($results, 'label');
    sort($labels);

    return $labels;
}

test('every picker is refused without the permission', function (string $entity) {
    CrossFixtures::signIn($this->world->users['super_admin'])->postJson("/pickers/{$entity}", ['q' => 'x'])->assertForbidden();
})->with(['persons', 'communities', 'formations', 'sites', 'groups', 'organizations']);

test('the users picker needs users.view and lists only users within jurisdiction', function () {
    CrossFixtures::signIn($this->chief)->postJson('/pickers/users', ['q' => ''])->assertForbidden();

    $names = array_column(pick($this->world->users['state_director'], 'users'), 'label');
    expect($names)->toContain($this->chief->name)
        ->not->toContain($this->world->users['director_general']->name);
});

test('communities: within jurisdiction, filtered by LGA', function () {
    Community::factory()->in($this->world->lgas['A1a'])->create(['name' => 'Ajegunle']);
    Community::factory()->in($this->world->lgas['A1b'])->create(['name' => 'Ajeromi']);
    Community::factory()->in($this->world->lgas['B1'])->create(['name' => 'Ajiwa']);

    expect(labels(pick($this->chief, 'communities', ['q' => 'Aje'])))->toBe(['Ajegunle'])
        ->and(labels(pick($this->world->users['state_director'], 'communities', ['q' => 'Aj'])))->toBe(['Ajegunle', 'Ajeromi'])
        ->and(labels(pick($this->world->users['state_director'], 'communities', ['q' => 'Aj', 'lga' => $this->world->lgas['A1b']->ulid])))->toBe(['Ajeromi']);
});

test('formations: jurisdiction, classification, agency and state filters', function () {
    $police = SecurityAgency::factory()->create();
    $army = SecurityAgency::factory()->create();
    AgencyFormation::factory()->of($police)->in($this->world->lgas['A1a'])->create(['name' => 'Apapa Division']);
    AgencyFormation::factory()->of($army)->in($this->world->lgas['A1a'])->create(['name' => 'Apapa Barracks']);
    AgencyFormation::factory()->of($police)->in($this->world->lgas['A1a'])->classified(Classification::Secret)->create(['name' => 'Apapa Secret Unit']);
    AgencyFormation::factory()->of($police)->in($this->world->lgas['B1'])->create(['name' => 'Apapa Far Away']);

    expect(labels(pick($this->chief, 'formations', ['q' => 'Apapa'])))->toBe(['Apapa Barracks', 'Apapa Division'])
        ->and(labels(pick($this->chief, 'formations', ['q' => 'Apapa', 'agency' => $police->ulid])))->toBe(['Apapa Division'])
        ->and(labels(pick($this->world->users['state_director'], 'formations', ['agency' => $police->ulid, 'state' => $this->world->states['A1']->ulid])))->toBe(['Apapa Division', 'Apapa Secret Unit'])
        ->and(pick($this->world->users['director_general'], 'formations', ['state' => $this->world->states['B1']->ulid])[0]['label'])->toBe('Apapa Far Away');
});

test('sites, groups and organizations respect their scopes', function () {
    $organization = Organization::factory()->create(['name' => 'Delta Oil']);
    OrganizationSite::factory()->of($organization)->in($this->world->lgas['A1a'])->create(['name' => 'Delta Depot']);
    OrganizationSite::factory()->of($organization)->in($this->world->lgas['B1'])->create(['name' => 'Delta Terminal']);
    OrganizationSite::factory()->of($organization)->in($this->world->lgas['A1a'])->classified(Classification::Confidential)->create(['name' => 'Delta Vault']);
    CriminalGroup::factory()->in($this->world->lgas['A1a'])->create(['name' => 'Delta Boys']);
    CriminalGroup::factory()->in($this->world->lgas['A1b'])->create(['name' => 'Delta Kings']);
    $security = OrganizationSector::factory()->create(['code' => 'private_security']);
    Organization::factory()->create(['name' => 'Delta Guards', 'organization_sector_id' => $security->id]);

    expect(labels(pick($this->chief, 'sites', ['q' => 'Delta'])))->toBe(['Delta Depot'])
        ->and(labels(pick($this->chief, 'groups', ['q' => 'Delta'])))->toBe(['Delta Boys'])
        // The directory is national.
        ->and(labels(pick($this->chief, 'organizations', ['q' => 'Delta'])))->toBe(['Delta Guards', 'Delta Oil'])
        ->and(pick($this->chief, 'organizations', ['q' => 'Delta Guards'])[0]['meta']['private_security'])->toBeTrue()
        ->and(labels(pick($this->chief, 'organizations', ['q' => 'Delta', 'sector' => 'private_security'])))->toBe(['Delta Guards']);
});

test('persons: link purpose is own jurisdiction only; incident purpose adds grant-held persons; classification applies', function () {
    Person::factory()->in($this->world->lgas['A1a'])->named('Musa', 'Garba')->create();
    Person::factory()->in($this->world->lgas['A1a'])->named('Musa', 'Secret')->classified(Classification::Secret)->create();
    Person::factory()->in($this->world->lgas['A1b'])->named('Musa', 'Neighbour')->create();
    CrossFixtures::person($this->world->lgas['B1'], ['first_name' => 'Musa', 'last_name' => 'Granted', 'other_names' => null]);
    CrossFixtures::grant($this->chief, CrossFixtures::directorOfB1($this->world), GrantScope::Profile);

    expect(labels(pick($this->chief, 'persons', ['q' => 'Musa'])))->toBe(['Musa Garba'])
        ->and(labels(pick($this->chief, 'persons', ['q' => 'Musa', 'purpose' => 'incident'])))->toBe(['Musa Garba', 'Musa Granted'])
        ->and(pick($this->chief, 'persons', ['q' => 'M']))->toBe([]);
});

test('results are capped at 20', function () {
    foreach (range(1, 25) as $n) {
        Community::factory()->in($this->world->lgas['A1a'])->create(['name' => "Oko {$n}"]);
    }

    expect(pick($this->chief, 'communities', ['q' => 'Oko']))->toHaveCount(20)
        ->and(pick($this->chief, 'communities'))->toHaveCount(20);
});
