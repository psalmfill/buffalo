<?php

use App\Actions\Users\OfficerPostings;
use App\Enums\LoginEvent;
use App\Models\Community;
use App\Models\GeopoliticalZone;
use App\Models\Lga;
use App\Models\LgaProfile;
use App\Models\LoginHistory;
use App\Models\SenatorialDistrict;
use App\Models\State;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/** Opens the current posting, as CreateUser does. */
function posted(User $user): User
{
    app(OfficerPostings::class)->open($user);

    return $user;
}

test('each role gets its dashboard component', function (string $who, string $component) {
    $world = JurisdictionWorld::build();

    $this->actingAs($world->users[$who])->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    ['super_admin', 'dashboard/system'],
    ['director_general', 'dashboard/national'],
    ['ddg_national', 'dashboard/national'],
    ['ddg_zone_a', 'dashboard/national'],
    ['state_director', 'dashboard/state'],
    ['deputy_state_director', 'dashboard/state'],
    ['lga_chief', 'dashboard/lga'],
]);

test('users without a role or with inconsistent jurisdiction data get no dashboard', function (string $who) {
    $world = JurisdictionWorld::build();

    $this->actingAs($world->users[$who])->get('/dashboard')->assertForbidden();
})->with(['no_role', 'inconsistent_state_director']);

test('guests are sent to sign in', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
});

test('the super admin dashboard has system data and no intelligence placeholders', function () {
    $horizon = Mockery::mock(MasterSupervisorRepository::class);
    $horizon->shouldReceive('all')->andReturn([]);
    app()->instance(MasterSupervisorRepository::class, $horizon);

    $setUp = ['password_changed_at' => now()];
    $admin = User::factory()->superAdmin()->create($setUp);
    User::factory()->directorGeneral()->create($setUp);
    User::factory()->stateDirector()->inactive()->create($setUp);
    User::factory()->lgaChief()->locked()->create($setUp);
    User::factory()->lgaChief()->create(['password_changed_at' => null]);

    LoginHistory::query()->insert([
        ['event' => LoginEvent::LoginFailed->value, 'email_attempted' => 'a@x.test', 'created_at' => now()->subHours(2)],
        ['event' => LoginEvent::TwoFactorFailed->value, 'email_attempted' => 'b@x.test', 'created_at' => now()->subHours(3)],
        ['event' => LoginEvent::LoginFailed->value, 'email_attempted' => 'c@x.test', 'created_at' => now()->subDays(2)],
        ['event' => LoginEvent::Locked->value, 'email_attempted' => 'd@x.test', 'created_at' => now()->subDays(3)],
        ['event' => LoginEvent::Locked->value, 'email_attempted' => 'e@x.test', 'created_at' => now()->subDays(9)],
    ]);

    $this->actingAs($admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->component('dashboard/system')
        ->missing('placeholders')
        ->missing('map')
        ->where('usersByRole', fn ($rows) => collect($rows)->pluck('count', 'role')->all() === [
            'super_admin' => 1,
            'director_general' => 1,
            'deputy_director_general' => 0,
            'state_director' => 1,
            'deputy_state_director' => 0,
            'lga_security_chief' => 2,
            '' => 0,
        ])
        ->where('usersByStatus.active', 4)
        ->where('usersByStatus.inactive', 1)
        ->where('usersByStatus.locked', 1)
        ->where('usersByStatus.setup_pending', 1)
        ->where('failedLogins24h', 2)
        ->where('lockouts7d', 1)
        ->has('recentAudit', 10)
        ->where('queueStatus', 'inactive'));
});

test('queue health reports Horizon running and paused', function (array $masters, string $expected) {
    $repository = Mockery::mock(MasterSupervisorRepository::class);
    $repository->shouldReceive('all')->andReturn($masters);
    app()->instance(MasterSupervisorRepository::class, $repository);

    $this->actingAs(User::factory()->superAdmin()->create())->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('queueStatus', $expected));
})->with([
    'running' => [[(object) ['status' => 'running']], 'running'],
    'paused' => [[(object) ['status' => 'running'], (object) ['status' => 'paused']], 'paused'],
]);

test('national dashboards show placeholders and national or zonal counts', function () {
    $world = JurisdictionWorld::build();

    $this->actingAs($world->users['director_general'])->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('scope.label', 'National')
        ->where('scope.zones', [])
        ->where('scope.states', State::query()->active()->count())
        ->where('scope.lgas', Lga::query()->active()->count())
        ->where('map.center', null)
        // Incident cards replaced the Phase 5 placeholders (5D).
        ->has('placeholders', 1)
        ->where('placeholders.0.title', 'Active flashpoints')
        ->where('placeholders.0.availableFromPhase', 6)
        // Phase 6 delivered it: the count of active flashpoints in reach.
        ->where('placeholders.0.count', 0)
        ->has('incidents.escalatedUnacknowledged.count'));

    $zoneA = $world->zones['A'];

    $this->flushSession();
    $this->actingAs($world->users['ddg_zone_a'])->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('scope.zones', [$zoneA->name])
        ->where('scope.label', $zoneA->name.' Zone')
        ->where('scope.states', 2)
        ->where('scope.lgas', 3)
        ->where('placeholders.0.scope', $zoneA->name.' Zone'));
});

test('a state director sees only their state\'s LGAs, with the chief from current postings', function () {
    $kano = State::factory()->create(['name' => 'Kano']);
    $lagos = State::factory()->create(['name' => 'Lagos']);
    $nassarawa = Lga::factory()->create(['state_id' => $kano->id, 'name' => 'Nassarawa']);
    $fagge = Lga::factory()->create(['state_id' => $kano->id, 'name' => 'Fagge']);
    $ikeja = Lga::factory()->create(['state_id' => $lagos->id, 'name' => 'Ikeja']);

    $chief = posted(User::factory()->lgaChief($nassarawa)->create(['name' => 'Current Chief']));
    posted(User::factory()->lgaChief($ikeja)->create(['name' => 'Lagos Chief']));

    // A former chief of Fagge: posting closed, so Fagge is vacant.
    $former = posted(User::factory()->lgaChief($fagge)->create(['name' => 'Former Chief']));
    app(OfficerPostings::class)->closeOpen($former);

    $director = User::factory()->stateDirector($kano)->create();

    $this->actingAs($director)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->component('dashboard/state')
        ->where('state.label', 'Kano State')
        ->where('lgas', [
            ['ulid' => $fagge->ulid, 'name' => 'Fagge', 'headquarters' => $fagge->headquarters, 'chief' => null, 'completeness' => 0, 'last_reviewed_at' => null],
            ['ulid' => $nassarawa->ulid, 'name' => 'Nassarawa', 'headquarters' => $nassarawa->headquarters, 'chief' => ['ulid' => $chief->ulid, 'name' => 'Current Chief'], 'completeness' => 0, 'last_reviewed_at' => null],
        ])
        ->has('placeholders', 1)
        ->where('placeholders.0.scope', 'Kano State'));
});

test('an LGA chief sees their LGA details', function () {
    $zone = GeopoliticalZone::factory()->create();
    $state = State::factory()->for($zone)->create(['name' => 'Lagos']);
    $district = SenatorialDistrict::factory()->create(['state_id' => $state->id, 'name' => 'Lagos West']);
    $lga = Lga::factory()->create([
        'state_id' => $state->id,
        'senatorial_district_id' => $district->id,
        'name' => 'Ikeja',
        'headquarters' => 'Ikeja',
    ]);

    $this->actingAs(User::factory()->lgaChief($lga)->create())->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->component('dashboard/lga')
        ->where('lga.label', 'Ikeja LGA')
        ->where('lga.state', 'Lagos State')
        ->where('lga.senatorialDistrict', 'Lagos West')
        ->where('lga.headquarters', 'Ikeja')
        ->where('map.center', null)
        ->where('placeholders.0.scope', 'Ikeja LGA'));
});

test('the map centres on the jurisdiction centroid when there is one', function () {
    $state = State::factory()->create();
    $lga = Lga::factory()->create(['state_id' => $state->id]);
    DB::statement("UPDATE states SET centroid = ST_GeogFromText('SRID=4326;POINT(8.52 12.05)') WHERE id = ?", [$state->id]);
    DB::statement("UPDATE lgas SET centroid = ST_GeogFromText('SRID=4326;POINT(8.51 11.99)') WHERE id = ?", [$lga->id]);

    $this->actingAs(User::factory()->stateDirector($state)->create())->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('map.center', [12.05, 8.52])->where('map.zoom', 8));

    $this->flushSession();
    $this->actingAs(User::factory()->lgaChief($lga)->create())->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('map.center', [11.99, 8.51])->where('map.zoom', 10));
});

test('the LGA chief dashboard shows profile completeness and communities', function () {
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    LgaProfile::factory()->forLga($lga)->create(['history' => 'x', 'terrain' => ['urban']]);
    Community::factory()->in($lga)->count(3)->create();

    $this->actingAs($world->users['lga_chief'])->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('profile.completeness', 50)
        ->where('profile.communities', 3)
        ->where('profile.missing', ['Economic activities', 'Major roads', 'Borders'])
        ->where('profile.last_reviewed_at', null));
});

test('national dashboards show average completeness by state within the jurisdiction', function () {
    $world = JurisdictionWorld::build();
    LgaProfile::factory()->forLga($world->lgas['A1a'])->create(['history' => 'x', 'economic_activities' => 'y', 'terrain' => ['urban'], 'major_roads' => 'r', 'borders' => 'b']);
    Community::factory()->in($world->lgas['A1a'])->create();

    $this->actingAs($world->users['ddg_zone_a'])->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('completenessByState', [
            ['state' => $world->states['A2']->name, 'lgas' => 1, 'average' => 0],
            ['state' => $world->states['A1']->name, 'lgas' => 2, 'average' => 50],
        ]));
});

test('the state dashboard lists least complete LGAs first', function () {
    $world = JurisdictionWorld::build();
    Community::factory()->in($world->lgas['A1b'])->create();

    $this->actingAs($world->users['state_director'])->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('lgas.0.ulid', $world->lgas['A1a']->ulid)
        ->where('lgas.0.completeness', 0)
        ->where('lgas.1.completeness', 17));
});
