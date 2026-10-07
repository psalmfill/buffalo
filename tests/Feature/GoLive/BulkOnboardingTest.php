<?php

use App\Models\AccountSetupToken;
use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use App\Notifications\AccountSetupNotification;
use App\Rules\MalwareFree;
use App\Support\Audit\AuditLog;
use App\Support\Auth\StepUp;
use Database\Seeders\ZonesAndStatesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Malware\FakeMalwareScanner;

/*
| Bulk user onboarding (Phase 9B.2): CSV import validated in full with a
| preview, one transaction, no links issued, file not stored; setup links
| in batches by email or in a handover session.
*/

const IMPORT_HEADER = 'name,email,phone,service_number,rank,role,state_code,lga_code,zone_codes';

beforeEach(function () {
    $this->seed(ZonesAndStatesSeeder::class);
    $this->lagos = State::query()->where('code', 'LA')->firstOrFail();
    $this->ikeja = Lga::factory()->create(['state_id' => $this->lagos->id, 'code' => 'IKJ', 'name' => 'Ikeja']);
    $this->admin = User::factory()->superAdmin()->create();
    $this->existing = User::factory()->directorGeneral()->create(['email' => 'existing@agency.gov.ng']);
});

function importCsv(string ...$lines): UploadedFile
{
    return UploadedFile::fake()->createWithContent('users.csv', implode("\n", [IMPORT_HEADER, ...$lines])."\n");
}

function previewRows(object $test, UploadedFile $file): array
{
    $rows = [];
    $test->actingAs($test->admin)->post(route('users.import.preview'), ['file' => $file])
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$rows) {
            $page->component('users/import-preview');
            $rows = $page->toArray()['props']['rows'];
        });

    return collect($rows)->keyBy('line')->all();
}

function validLines(): array
{
    return [
        'Ada Obi,ada.obi@agency.gov.ng,08030000001,SN-1,Director,director_general,,,',
        'Bello Musa,bello.musa@agency.gov.ng,,SN-2,,state_director,LA,,',
        'Chika Eze,chika.eze@agency.gov.ng,,,,lga_security_chief,LA,IKJ,',
        'Dayo Ade,dayo.ade@agency.gov.ng,,,,deputy_director_general,,,SW;SE',
        'Existing Person,EXISTING@agency.gov.ng,,,,director_general,,,',
    ];
}

test('every row is validated and classified before anything is written', function () {
    $rows = previewRows($this, importCsv(...[
        ...validLines(),
        'No Role,norole@agency.gov.ng,,,,chief,,,',
        'Admin,admin2@agency.gov.ng,,,,super_admin,,,',
        'No State,nostate@agency.gov.ng,,,,state_director,,,',
        'Wrong Level,wrong@agency.gov.ng,,,,state_director,LA,IKJ,',
        'Bad Place,badplace@agency.gov.ng,,,,lga_security_chief,LA,ZZZ,',
        'Bad State,badstate@agency.gov.ng,,,,state_director,XX,,',
        'Zones,zones@agency.gov.ng,,,,state_director,LA,,SW',
        'Again,ada.obi@agency.gov.ng,,,,director_general,,,',
        'Service,service@agency.gov.ng,,SN-1,,director_general,,,',
        'Not An Email,not-an-email,,,,director_general,,,',
    ]));

    expect(array_map(fn ($row) => $row['status'], $rows))->toBe([
        2 => 'new', 3 => 'new', 4 => 'new', 5 => 'new', 6 => 'exists',
        7 => 'error', 8 => 'error', 9 => 'error', 10 => 'error', 11 => 'error',
        12 => 'error', 13 => 'error', 14 => 'error', 15 => 'error', 16 => 'error',
    ])
        ->and($rows[4]['place'])->toBe('Ikeja, Lagos')
        ->and($rows[7]['errors'][0])->toContain('Unknown role "chief"')
        ->and($rows[8]['errors'])->toContain('Super admin accounts cannot be imported; create them one at a time.')
        ->and($rows[9]['errors'])->toContain('A State Director must be assigned a state.')
        ->and($rows[10]['errors'])->toContain('A State Director is assigned a state, not an LGA.')
        ->and($rows[11]['errors'])->toContain('Unknown LGA code "ZZZ" in Lagos.')
        ->and($rows[12]['errors'])->toContain('Unknown state code "XX".')
        ->and($rows[13]['errors'])->toContain('Only a Deputy Director General can be restricted to geopolitical zones.')
        ->and($rows[14]['errors'])->toContain('The same email appears on line 2.')
        ->and($rows[15]['errors'])->toContain('The same service number appears on line 2.')
        ->and(User::query()->count())->toBe(2);
});

test('only super admins may import', function () {
    $dg = User::factory()->directorGeneral()->create();

    $this->actingAs($dg)->get(route('users.import.create'))->assertForbidden();
    $this->actingAs($dg)->post(route('users.import.preview'), ['file' => importCsv(...validLines())])->assertForbidden();
});

test('the upload is malware-scanned', function () {
    $this->actingAs($this->admin)
        ->post(route('users.import.preview'), ['file' => importCsv('X,'.FakeMalwareScanner::eicar().',,,,director_general,,,')])
        ->assertSessionHasErrors(['file' => MalwareFree::INFECTED]);
});

test('the uploaded file is not stored anywhere', function () {
    foreach (array_keys(config('filesystems.disks')) as $disk) {
        Storage::fake($disk);
    }

    previewRows($this, importCsv(...validLines()));

    foreach (array_keys(config('filesystems.disks')) as $disk) {
        expect(Storage::disk($disk)->allFiles())->toBe([], "disk {$disk}");
    }
});

test('confirming needs step-up, creates the new accounts in one go, and issues no links', function () {
    $rows = array_values(array_map(fn ($row) => array_intersect_key($row, array_flip(['line', 'name', 'email', 'phone', 'service_number', 'rank', 'role', 'state_code', 'lga_code', 'zone_codes'])), previewRows($this, importCsv(...validLines()))));

    $this->actingAs($this->admin)->postJson(route('users.import.store'), ['rows' => $rows])->assertStatus(428);
    expect(User::query()->count())->toBe(2);

    $this->withSession([StepUp::SESSION_KEY => now()->timestamp])
        ->actingAs($this->admin)->post(route('users.import.store'), ['rows' => $rows])
        ->assertRedirect(route('users.onboarding', ['status' => 'setup_pending']));

    $chief = User::query()->where('email', 'chika.eze@agency.gov.ng')->firstOrFail();
    $ddg = User::query()->where('email', 'dayo.ade@agency.gov.ng')->firstOrFail();

    expect(User::query()->count())->toBe(6)
        ->and($chief->lga_id)->toBe($this->ikeja->id)
        ->and($chief->state_id)->toBe($this->lagos->id)
        ->and($ddg->zones->pluck('code')->sort()->values()->all())->toBe(['SE', 'SW'])
        ->and(User::query()->where('email', 'ada.obi@agency.gov.ng')->value('service_number'))->toBe('SN-1')
        ->and(User::query()->whereNotNull('password_changed_at')->where('email', 'like', '%@agency.gov.ng')->where('email', '!=', 'existing@agency.gov.ng')->count())->toBe(0)
        ->and(AccountSetupToken::query()->count())->toBe(0);

    $event = Activity::query()->where('log_name', AuditLog::LOG_NAME)->where('event', 'users_imported')->firstOrFail();
    expect($event->properties->toArray())->toMatchArray(['created' => 4, 'skipped_existing' => 1]);
});

test('a confirmation with any row in error creates nothing', function () {
    $this->withSession([StepUp::SESSION_KEY => now()->timestamp])
        ->actingAs($this->admin)->post(route('users.import.store'), ['rows' => [
            ['line' => 2, 'name' => 'Ada', 'email' => 'ada@agency.gov.ng', 'role' => 'director_general'],
            ['line' => 3, 'name' => 'Bad', 'email' => 'bad@agency.gov.ng', 'role' => 'state_director'],
        ]])
        ->assertSessionHasErrors('rows');

    expect(User::query()->where('email', 'ada@agency.gov.ng')->exists())->toBeFalse();
});

test('a failure part-way through rolls the whole import back', function () {
    // The second account fails while being created, after the first one.
    User::creating(function (User $user): void {
        if ($user->email === 'boom@agency.gov.ng') {
            throw new RuntimeException('boom');
        }
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->withSession([StepUp::SESSION_KEY => now()->timestamp])
        ->actingAs($this->admin)->post(route('users.import.store'), ['rows' => [
            ['line' => 2, 'name' => 'Ada', 'email' => 'ada@agency.gov.ng', 'role' => 'director_general'],
            ['line' => 3, 'name' => 'Boom', 'email' => 'boom@agency.gov.ng', 'role' => 'director_general'],
        ]]))->toThrow(RuntimeException::class, 'boom');

    expect(User::query()->where('email', 'ada@agency.gov.ng')->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'users_imported')->exists())->toBeFalse();
});

test('setup links can be emailed in a batch, only to accounts waiting for setup', function () {
    config(['buffalo.mail_enabled' => true]);
    Notification::fake();
    $pending = User::factory()->stateDirector($this->lagos)->count(2)->create(['password_changed_at' => null, 'two_factor_confirmed_at' => null]);
    $active = User::factory()->stateDirector($this->lagos)->create(['password_changed_at' => now()]);
    $ulids = [...$pending->pluck('ulid'), $active->ulid];

    $this->actingAs($this->admin)->postJson(route('users.onboarding.email'), ['users' => $ulids])->assertStatus(428);
    Notification::assertNothingSent();

    $this->withSession([StepUp::SESSION_KEY => now()->timestamp])
        ->actingAs($this->admin)->post(route('users.onboarding.email'), ['users' => $ulids])
        ->assertSessionHas('toast.message', '2 setup link(s) emailed, 1 skipped (setup already done or not allowed).');

    Notification::assertSentTo($pending, AccountSetupNotification::class);
    Notification::assertNotSentTo($active, AccountSetupNotification::class);

    expect(Activity::query()->where('event', 'setup_link_issued')->get()->pluck('properties.delivery')->all())->toBe(['email', 'email']);
});

test('batch email is refused when mail is not enabled', function () {
    config(['buffalo.mail_enabled' => false]);
    Notification::fake();
    $pending = User::factory()->stateDirector($this->lagos)->create(['password_changed_at' => null]);

    $this->withSession([StepUp::SESSION_KEY => now()->timestamp])
        ->actingAs($this->admin)->post(route('users.onboarding.email'), ['users' => [$pending->ulid]])
        ->assertSessionHasErrors('delivery');
    Notification::assertNothingSent();
});

test('a handover session lists the users without any link, and each reveal is logged', function () {
    $users = User::factory()->stateDirector($this->lagos)->count(3)->create(['password_changed_at' => null]);

    $this->actingAs($this->admin)
        ->post(route('users.onboarding.handover.start'), ['users' => $users->pluck('ulid')->all()])
        ->assertRedirect(route('users.onboarding.handover'));

    $response = $this->actingAs($this->admin)->get(route('users.onboarding.handover'));
    $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('users/handover')->has('users', 3));
    expect($response->getContent())->not->toContain('account/setup/')
        ->and(AccountSetupToken::query()->count())->toBe(0);

    // Reveal: the existing setup-link endpoint, delivery "handover", step-up.
    $url = route('users.setup-link', $users->first());
    $this->actingAs($this->admin)->postJson($url, ['delivery' => 'handover'])->assertStatus(428);

    $reveal = $this->withSession([StepUp::SESSION_KEY => now()->timestamp])
        ->actingAs($this->admin)->postJson($url, ['delivery' => 'handover']);
    $reveal->assertOk()->assertJsonPath('delivery', 'handover')->assertHeader('Cache-Control', 'no-store, private');
    expect($reveal->json('url'))->toContain('/account/setup/');

    $event = Activity::query()->where('event', 'setup_link_issued')->sole();
    expect($event->properties['delivery'])->toBe('handover')
        ->and($event->subject_id)->toBe($users->first()->id)
        ->and(json_encode($event->properties))->not->toContain('account/setup');
});

test('other roles cannot use onboarding', function () {
    $dg = User::factory()->directorGeneral()->create();
    $this->actingAs($dg)->get(route('users.onboarding'))->assertForbidden();
    $this->actingAs($dg)->post(route('users.onboarding.handover.start'), ['users' => [$dg->ulid]])->assertForbidden();
});

test('setup links are never exported: only the one-at-a-time endpoint returns one', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains(implode(' ', $route->gatherMiddleware()), 'auth'))
        ->map(fn ($route) => $route->getName())
        ->filter(fn ($name) => $name !== null && (str_contains($name, 'setup-link') || str_contains($name, 'onboarding')))
        ->values()->sort()->values()->all();

    expect($routes)->toBe(['users.onboarding', 'users.onboarding.email', 'users.onboarding.handover', 'users.onboarding.handover.start', 'users.setup-link']);

    $page = $this->actingAs($this->admin)->get(route('users.onboarding', ['status' => 'all']));
    expect($page->getContent())->not->toContain('account/setup/');
});
