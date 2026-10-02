<?php

use App\Enums\Classification;
use App\Enums\GrantScope;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\AgencyFormation;
use App\Models\Community;
use App\Models\CriminalGroup;
use App\Models\Incident;
use App\Models\IncidentAgency;
use App\Models\IncidentCasualty;
use App\Models\IncidentCriminalGroup;
use App\Models\IncidentPerson;
use App\Models\IncidentProperty;
use App\Models\Lga;
use App\Models\Organization;
use App\Models\OrganizationSite;
use App\Models\Person;
use App\Models\PropertyType;
use App\Models\RecordAccessLog;
use App\Models\SecurityAgency;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Incidents\WorkflowFixtures as W;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(function () {
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->chief = $this->world->users['lga_chief'];
    $this->sd = $this->world->users['state_director'];
    $this->lga = $this->world->lgas['A1a'];
});

function submitted(Lga|string $lga, array $attributes = []): Incident
{
    $world = test()->world;
    $place = is_string($lga) ? $world->lgas[$lga] : $lga;

    return W::at(W::draft($place, $world->users['state_director'], $attributes), IncidentStatus::Submitted);
}

/** Forces a child row onto $incident. */
function child(string $class, Incident $incident, array $attributes): void
{
    $class::query()->forceCreate(['incident_id' => $incident->id, 'state_id' => $incident->state_id, 'lga_id' => $incident->lga_id, ...$attributes]);
}

/*
|--------------------------------------------------------------------------
| Detail page and print
|--------------------------------------------------------------------------
*/

test('detail tabs: each section respects child scopes; duplicates only for reviewers', function () {
    $incident = submitted('A1a');
    $secretPerson = Person::factory()->in($this->lga)->classified(Classification::Secret)->create();
    child(IncidentPerson::class, $incident, ['person_id' => $secretPerson->id, 'involvement' => 'suspect']);

    CrossFixtures::signIn($this->chief)->get("/incidents/{$incident->ulid}?tab=people")
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'people')->where('persons.0.person', null)->where('persons.0.person_hidden', true));

    // Reviewers only: others fall back to the overview.
    CrossFixtures::signIn($this->chief)->get("/incidents/{$incident->ulid}?tab=duplicates")->assertInertia(fn (Assert $page) => $page->where('tab', 'overview'));
    CrossFixtures::signIn($this->sd)->get("/incidents/{$incident->ulid}?tab=duplicates")->assertInertia(fn (Assert $page) => $page->where('tab', 'duplicates'));

    foreach (['response', 'evidence', 'timeline', 'history'] as $tab) {
        CrossFixtures::signIn($this->sd)->get("/incidents/{$incident->ulid}?tab={$tab}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('tab', $tab));
    }
});

test('the print view: classification markings, footer, a print access log, nothing above clearance', function () {
    $incident = submitted('A1a', ['classification' => Classification::Confidential, 'title' => 'Printable incident']);
    W::step($this->sd, $incident, 'updates', ['type' => 'intelligence', 'body' => 'Secret informant report', 'classification' => 'secret']);
    W::step($this->sd, $incident, 'updates', ['type' => 'follow_up', 'body' => 'Open follow-up', 'classification' => 'confidential']);
    $dsd = $this->world->users['deputy_state_director'];

    CrossFixtures::signIn($dsd)->get("/incidents/{$incident->ulid}/print")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('incidents/print')
            ->where('incident.classification.value', 'confidential')
            ->where('printedBy', $dsd->name)
            ->has('printedAt')
            ->where('timeline', fn ($entries) => collect($entries)->pluck('body')->contains('Open follow-up') && ! collect($entries)->pluck('body')->contains('Secret informant report')));

    expect(RecordAccessLog::query()->where('subject_type', $incident->getMorphClass())->where('subject_id', $incident->id)->where('action', 'print')->where('user_id', $dsd->id)->exists())->toBeTrue();

    // Out of reach: 404.
    CrossFixtures::signIn($this->chief)->get("/incidents/{$incident->ulid}/print")->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Incidents tabs on other records
|--------------------------------------------------------------------------
*/

test('person tab: incidents with involvement; hidden when none; hidden under a grant', function () {
    $person = CrossFixtures::person($this->world->lgas['B1']);
    $own = Person::factory()->in($this->lga)->create();
    $incident = submitted('A1a', ['title' => 'Kidnapping involving Garba']);
    child(IncidentPerson::class, $incident, ['person_id' => $own->id, 'involvement' => 'victim']);
    child(IncidentPerson::class, $incident, ['person_id' => $person->id, 'involvement' => 'suspect']);
    $draft = W::draft($this->lga, $this->sd, ['title' => 'Draft only']);
    child(IncidentPerson::class, $draft, ['person_id' => $own->id, 'involvement' => 'witness']);

    CrossFixtures::signIn($this->chief)->get("/persons/{$own->ulid}?tab=incidents")
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'incidents')
            ->where('tabs', fn ($tabs) => in_array('incidents', collect($tabs)->all(), true))
            ->has('incidents.data', 1)
            ->where('incidents.data.0.title', 'Kidnapping involving Garba')
            ->where('incidents.data.0.involvement', 'Victim'));

    // No incidents: the tab is not offered.
    $other = Person::factory()->in($this->lga)->create();
    CrossFixtures::signIn($this->chief)->get("/persons/{$other->ulid}?tab=incidents")
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'profile')->where('tabs', fn ($tabs) => ! in_array('incidents', collect($tabs)->all(), true)));

    // The B1 person seen by the chief under a grant: no incidents tab.
    CrossFixtures::grant($this->chief, CrossFixtures::directorOfB1($this->world), GrantScope::Full);
    CrossFixtures::signIn($this->chief)->get("/persons/{$person->ulid}?tab=incidents")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tabs', fn ($tabs) => ! in_array('incidents', collect($tabs)->all(), true))->missing('incidents.data'));
});

test('group, formation, site, community and LGA tabs list the visible linked incidents', function () {
    $group = CriminalGroup::factory()->in($this->lga)->create();
    $agency = SecurityAgency::factory()->create();
    $formation = AgencyFormation::factory()->of($agency)->in($this->lga)->create();
    $site = OrganizationSite::factory()->of(Organization::factory()->create())->in($this->lga)->create();
    $community = Community::factory()->in($this->lga)->create();

    $linked = submitted('A1a', ['title' => 'Linked', 'community_id' => $community->id, 'occurred_at' => now()->subDays(3)]);
    $secret = submitted('A1a', ['title' => 'Linked secret', 'classification' => Classification::Secret, 'community_id' => $community->id]);
    $invalid = submitted('A1a', ['title' => 'Linked invalid', 'community_id' => $community->id]);
    foreach ([$linked, $secret, $invalid] as $incident) {
        child(IncidentCriminalGroup::class, $incident, ['criminal_group_id' => $group->id, 'role' => 'suspected']);
        child(IncidentAgency::class, $incident, ['security_agency_id' => $agency->id, 'agency_formation_id' => $formation->id, 'role' => 'first_responder']);
        child(IncidentProperty::class, $incident, ['property_type_id' => PropertyType::factory()->create()->id, 'organization_site_id' => $site->id]);
    }
    // Invalidated after linking (invalid incidents are locked).
    W::at($invalid, IncidentStatus::Invalid, ['invalid_reason' => 'false_report']);

    $pages = [
        "/criminal-groups/{$group->ulid}?tab=incidents" => 'incidents',
        "/formations/{$formation->ulid}?tab=incidents" => 'incidents',
        "/organization-sites/{$site->ulid}?tab=incidents" => 'incidents',
        "/communities/{$community->ulid}?tab=incidents" => 'incidents',
        "/lgas/{$this->lga->ulid}?tab=incidents" => 'incidents.rows',
    ];

    foreach ($pages as $url => $key) {
        CrossFixtures::signIn($this->chief)->get($url)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tab', 'incidents')->where("{$key}.data", fn ($rows) => collect($rows)->pluck('title')->all() === ['Linked']));
        CrossFixtures::signIn($this->sd)->get($url)
            ->assertInertia(fn (Assert $page) => $page->where("{$key}.data", fn ($rows) => collect($rows)->pluck('title')->sort()->values()->all() === ['Linked', 'Linked secret']));
    }

    CrossFixtures::signIn($this->chief)->get("/lgas/{$this->lga->ulid}?tab=incidents")
        ->assertInertia(fn (Assert $page) => $page->where('incidents.last90ByType.0.count', 1));

    // Nothing linked: the tab is hidden.
    $empty = CriminalGroup::factory()->in($this->lga)->create();
    CrossFixtures::signIn($this->chief)->get("/criminal-groups/{$empty->ulid}?tab=incidents")
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'overview')->where('tabs', fn ($tabs) => ! in_array('incidents', collect($tabs)->all(), true)));
    $emptySite = OrganizationSite::factory()->of(Organization::factory()->create())->in($this->lga)->create();
    CrossFixtures::signIn($this->chief)->get("/organization-sites/{$emptySite->ulid}?tab=incidents")
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'overview')->where('hasIncidents', false));
});

/*
|--------------------------------------------------------------------------
| Dashboards
|--------------------------------------------------------------------------
*/

/** A fixed fixture set in A1a, A1b and B1. */
function dashboardFixtures(): void
{
    $world = test()->world;
    $chief = $world->users['lga_chief'];

    $robbery = submitted('A1a', ['title' => 'Robbery', 'severity' => Severity::High, 'occurred_at' => now()->subDays(2), 'location' => Incident::pointFrom(6.5, 3.4)]);
    DB::table('incidents')->where('id', $robbery->id)->update(['reporting_officer_id' => $chief->id, 'created_by' => $chief->id]);
    child(IncidentCasualty::class, $robbery, ['outcome' => 'killed', 'group' => 'civilian', 'adult_male' => 2]);

    $critical = submitted('A1b', ['title' => 'Critical attack', 'severity' => Severity::Critical, 'occurred_at' => now()->subDays(1), 'escalated_at' => now(), 'location' => Incident::pointFrom(6.6, 3.5)]);
    child(IncidentCasualty::class, $critical, ['outcome' => 'abducted', 'group' => 'civilian', 'adult_female' => 4]);
    $police = SecurityAgency::factory()->create(['short_name' => 'NPF']);
    child(IncidentCasualty::class, $critical, ['outcome' => 'injured', 'group' => 'security_personnel', 'security_agency_id' => $police->id, 'adult_male' => 1]);

    submitted('A1a', ['title' => 'Secret matter', 'severity' => Severity::Critical, 'occurred_at' => now()->subDays(3), 'classification' => Classification::Secret]);
    submitted('B1', ['title' => 'Far away', 'occurred_at' => now()->subDays(4)]);
    W::at(submitted('A1a', ['title' => 'Invalid', 'occurred_at' => now()->subDays(1)]), IncidentStatus::Invalid, ['invalid_reason' => 'false_report']);
    W::draft($world->lgas['A1a'], $chief, ['title' => 'My draft', 'occurred_at' => now()->subDays(1)]);
    W::at(W::draft($world->lgas['A1a'], $chief, ['title' => 'Returned to me']), IncidentStatus::Returned);
}

function dashboard(User $user): array
{
    return CrossFixtures::signIn($user)->get('/dashboard')->assertOk()->inertiaProps('incidents');
}

test('LGA chief dashboard figures', function () {
    dashboardFixtures();
    $d = dashboard($this->chief);

    // Robbery and the returned report (both mine, A1a); no secret, invalid or draft.
    expect(collect($d['byType'])->sum('count'))->toBe(2)
        ->and(collect($d['myOpenByStatus'])->firstWhere('status.value', 'submitted')['count'])->toBe(1)
        ->and(collect($d['myOpenByStatus'])->firstWhere('status.value', 'returned')['count'])->toBe(1)
        ->and(array_column($d['returned'], 'title'))->toBe(['Returned to me'])
        ->and($d['drafts']['count'])->toBe(1)
        ->and(array_column($d['latest'], 'title'))->toEqualCanonicalizing(['Robbery', 'Returned to me'])
        ->and($d['channel'])->toBe("states.{$this->world->states['A1']->ulid}.incidents");
});

test('state dashboard figures follow clearance and exclude drafts and invalid reports', function () {
    dashboardFixtures();
    $sd = dashboard($this->sd);
    $dsd = dashboard($this->world->users['deputy_state_director']);

    expect($sd['awaiting']['count'])->toBe(3)
        ->and($dsd['awaiting']['count'])->toBe(2)
        // Open critical or high: robbery, critical attack, secret matter and the returned report.
        ->and($sd['criticalHigh']['count'])->toBe(4)
        ->and($dsd['criticalHigh']['count'])->toBe(3)
        ->and(collect($sd['byLga'])->sum('count'))->toBe(4)
        ->and(collect($sd['casualtiesByOutcome'])->firstWhere('label', 'Killed')['count'])->toBe(2)
        ->and(collect($sd['casualtiesByOutcome'])->firstWhere('label', 'Abducted')['count'])->toBe(4)
        ->and(array_column($sd['escalated'], 'title'))->toBe(['Critical attack'])
        // Phase 6C: dashboards carry no raw incident points (aggregated map layers instead).
        ->and($sd)->not->toHaveKey('map7')
        ->and(collect($sd['awaiting']['rows'])->every(fn (array $r) => ! array_key_exists('id', $r)))->toBeTrue();
});

test('national dashboard figures, nationally and for a zonal DDG', function () {
    config(['buffalo.incidents.verify_within_minutes.high' => 1, 'buffalo.incidents.verify_within_minutes.critical' => 1]);
    dashboardFixtures();
    $this->travel(5)->minutes();
    $dg = dashboard($this->world->users['director_general']);
    $zoneB = dashboard($this->world->users['ddg_zone_b']);

    expect(array_column($dg['escalatedUnacknowledged']['rows'], 'title'))->toBe(['Critical attack'])
        ->and($dg['escalatedUnacknowledged']['count'])->toBe(1)
        ->and(array_column($dg['critical7']['rows'], 'title'))->toEqualCanonicalizing(['Critical attack', 'Secret matter'])
        ->and($dg['critical7']['count'])->toBe(2)
        ->and(collect($dg['byState'])->sum('count'))->toBe(5)
        ->and(collect($dg['casualtiesByGroup'])->firstWhere('label', 'Security personnel')['count'])->toBe(1)
        ->and($dg['securityCasualtiesByAgency'])->toBe([['label' => 'NPF', 'count' => 1]])
        ->and(collect($dg['statesOverdue'])->sum('count'))->toBeGreaterThan(0)
        ->and(collect($zoneB['byState'])->pluck('label')->all())->toBe([$this->world->states['B1']->name])
        ->and($zoneB['escalatedUnacknowledged']['count'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Demo data
|--------------------------------------------------------------------------
*/

test('buffalo:seed-demo-incidents refuses to run outside local', function () {
    expect(app()->environment())->not->toBe('local');

    $this->artisan('buffalo:seed-demo-incidents', ['--count' => 10])->assertFailed();
    expect(Incident::query()->withoutGlobalScopes()->where('title', 'like', '[Demo]%')->count())->toBe(0);
});
