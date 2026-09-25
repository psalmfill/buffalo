<?php

use App\Enums\Permission;
use App\Models\Lga;
use App\Models\User;
use App\Support\Auth\SessionKeys;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(function () {
    $this->world = JurisdictionWorld::build();
    // In state A1 (visible to A1 roles), and one in zone B.
    $this->target = User::factory()->lgaChief($this->world->lgas['A1b'])->create();
    $this->outsider = User::factory()->lgaChief($this->world->lgas['B1'])->create();
});

/**
 * Management routes for a target user: [method, uri, data].
 *
 * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
 */
function managementRoutes(User $target, Lga $lga): array
{
    return [
        ['get', '/users/create', []],
        ['post', '/users', ['name' => 'X', 'email' => 'new@buffalo.test', 'role' => 'director_general']],
        ['put', "/users/{$target->ulid}", ['name' => 'Changed', 'email' => $target->email]],
        ['put', "/users/{$target->ulid}/jurisdiction", ['role' => 'lga_security_chief', 'lga' => $lga->ulid]],
        ['post', "/users/{$target->ulid}/unlock", []],
        ['post', "/users/{$target->ulid}/reset-two-factor", []],
        ['post', "/users/{$target->ulid}/setup-link", ['delivery' => 'manual']],
        ['post', "/users/{$target->ulid}/deactivate", ['reason' => 'Test']],
        ['post', "/users/{$target->ulid}/reactivate", ['reason' => 'Test']],
    ];
}

test('super admins can use every user management route', function () {
    $admin = $this->world->users['super_admin'];

    foreach (managementRoutes($this->target, $this->world->lgas['A1a']) as [$method, $uri, $data]) {
        $response = $this->actingAs($admin)->from('/users')->json(strtoupper($method), $uri, $data);

        expect($response->status())->toBeLessThan(400, "{$method} {$uri} returned {$response->status()}");
    }
});

test('every other role is forbidden from managing users', function (string $who) {
    $actor = $this->world->users[$who];

    foreach (managementRoutes($this->target, $this->world->lgas['A1a']) as [$method, $uri, $data]) {
        $status = $this->actingAs($actor)->json(strtoupper($method), $uri, $data)->status();

        expect($status)->toBeIn([403, 404], "{$who}: {$method} {$uri} returned {$status}");
    }

    expect($this->target->fresh()->name)->not->toBe('Changed')
        ->and($this->target->fresh()->is_active)->toBeTrue();
})->with(['director_general', 'ddg_national', 'ddg_zone_a', 'state_director', 'deputy_state_director', 'lga_chief', 'no_role']);

test('users.view holders get a read-only list and detail within their jurisdiction', function () {
    $director = $this->world->users['state_director'];

    $this->actingAs($director)->get('/users')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('users/index')
        ->where('can.create', false)
        ->where('users.data', fn ($rows) => collect($rows)->pluck('ulid')->contains($this->target->ulid)
            && ! collect($rows)->pluck('ulid')->contains($this->outsider->ulid)));

    $this->actingAs($director)->get("/users/{$this->target->ulid}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('users/show')
        ->where('can', fn ($can) => collect($can)->every(fn ($allowed) => $allowed === false))
        ->where('tabs', ['profile', 'postings'])
        ->where('jurisdictionOptions', null));
});

test('a state director cannot view a user in another state, even by ULID', function () {
    $this->actingAs($this->world->users['state_director'])
        ->get("/users/{$this->outsider->ulid}")
        ->assertNotFound();
});

test('LGA chiefs and role-less users cannot see the user list', function (string $who) {
    $this->actingAs($this->world->users[$who])->get('/users')->assertForbidden();
})->with(['lga_chief', 'no_role']);

test('user list filters, search and sorting', function () {
    $admin = $this->world->users['super_admin'];
    $this->target->forceFill(['service_number' => 'SN-777'])->save();

    $this->actingAs($admin)->get('/users?search=sn-777')->assertInertia(fn (Assert $page) => $page->where('users.data.0.ulid', $this->target->ulid)->has('users.data', 1));
    $this->actingAs($admin)->get('/users?role=state_director')->assertInertia(fn (Assert $page) => $page->where('users.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['role']['value'] === 'state_director')));
    $this->actingAs($admin)->get('/users?state='.$this->world->states['B1']->ulid)->assertInertia(fn (Assert $page) => $page->where('users.data', fn ($rows) => collect($rows)->pluck('ulid')->contains($this->outsider->ulid) && ! collect($rows)->pluck('ulid')->contains($this->target->ulid)));
    $this->actingAs($admin)->get('/users?status=inactive')->assertInertia(fn (Assert $page) => $page->where('users.data', fn ($rows) => collect($rows)->every(fn ($row) => in_array('inactive', $row['statuses'], true))));
    $this->actingAs($admin)->get('/users?sort=email&direction=desc')->assertOk();
    $this->actingAs($admin)->get('/users?sort=password')->assertSessionHasErrors('sort');
});

test('user rows never contain numeric ids or secrets', function () {
    $response = $this->actingAs($this->world->users['super_admin'])->get('/users');

    $json = json_encode($response->viewData('page')['props']['users']['data']);

    expect($json)->not->toContain('"id"')->not->toContain('password')->not->toContain('two_factor_secret');
});

test('creating a user validates role and jurisdiction through HTTP', function (array $data, string $field) {
    $this->actingAs($this->world->users['super_admin'])
        ->from('/users/create')
        ->post('/users', ['name' => 'New', 'email' => 'new@buffalo.test', ...$data])
        ->assertSessionHasErrors($field);

    expect(User::query()->where('email', 'new@buffalo.test')->exists())->toBeFalse();
})->with([
    'state director without state' => [['role' => 'state_director'], 'state'],
    'LGA chief without LGA' => [['role' => 'lga_security_chief'], 'lga'],
    'zones on a state director' => [fn () => ['role' => 'state_director', 'state' => $this->world->states['A1']->ulid, 'zones' => [$this->world->zones['A']->ulid]], 'zones'],
    'unknown role' => [['role' => 'emperor'], 'role'],
    'unknown state' => [['role' => 'state_director', 'state' => '01jzzzzzzzzzzzzzzzzzzzzzzz'], 'state'],
]);

test('a created LGA chief gets a posting and the setup dialog opens', function () {
    $admin = $this->world->users['super_admin'];

    $response = $this->actingAs($admin)->post('/users', [
        'name' => 'Ngozi Okafor',
        'email' => 'ngozi@buffalo.test',
        'role' => 'lga_security_chief',
        'state' => $this->world->states['A1']->ulid,
        'lga' => $this->world->lgas['A1a']->ulid,
    ]);

    $user = User::query()->where('email', 'ngozi@buffalo.test')->sole();
    $response->assertRedirect("/users/{$user->ulid}")->assertSessionHas('userCreated', true);

    expect($user->lga_id)->toBe($this->world->lgas['A1a']->id)
        ->and($user->postings()->count())->toBe(1);
});

test('a manually delivered setup link is returned once, as JSON, and never stored in the session', function () {
    $response = $this->actingAs($this->world->users['super_admin'])
        ->postJson("/users/{$this->target->ulid}/setup-link", ['delivery' => 'manual'])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('delivery', 'manual');

    $url = $response->json('url');

    expect($url)->toContain('/account/setup/')
        ->and(json_encode(session()->all()))->not->toContain(basename(parse_url($url, PHP_URL_PATH)));
});

test('email delivery is refused when mail is off', function () {
    config(['buffalo.mail_enabled' => false]);

    $this->actingAs($this->world->users['super_admin'])
        ->postJson("/users/{$this->target->ulid}/setup-link", ['delivery' => 'email'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('delivery');
});

test('the last active super admin cannot be deactivated or given another role through HTTP', function () {
    $admin = $this->world->users['super_admin'];

    $this->actingAs($admin)->postJson("/users/{$admin->ulid}/deactivate", ['reason' => 'x'])->assertForbidden();
    $this->actingAs($admin)->putJson("/users/{$admin->ulid}/jurisdiction", ['role' => 'director_general'])->assertForbidden();

    expect($admin->fresh()->is_active)->toBeTrue();
});

test('deactivation requires a reason and signs the user out at once', function () {
    $admin = $this->world->users['super_admin'];
    $target = $this->target;
    $session = [SessionKeys::VERSION => $target->fresh()->session_version];

    $this->actingAs($admin)->postJson("/users/{$target->ulid}/deactivate", [])->assertJsonValidationErrors('reason');
    $this->actingAs($admin)->post("/users/{$target->ulid}/deactivate", ['reason' => 'Misconduct'])->assertRedirect();

    $this->flushSession();
    $this->actingAs($target->fresh())->withSession($session)->get('/dashboard')->assertRedirect(route('login'));
});

test('unlocking clears the lock', function () {
    $locked = User::factory()->lgaChief($this->world->lgas['A1a'])->locked()->create(['failed_login_count' => 3, 'lockout_count' => 2]);

    $this->actingAs($this->world->users['super_admin'])->post("/users/{$locked->ulid}/unlock")->assertRedirect();

    expect($locked->fresh()->locked_until)->toBeNull()
        ->and($locked->fresh()->lockout_count)->toBe(0);
});

test('the detail page shows postings, login history and activity tabs to a super admin', function () {
    $admin = $this->world->users['super_admin'];

    foreach (['postings', 'logins', 'activity'] as $tab) {
        $this->actingAs($admin)->get("/users/{$this->target->ulid}?tab={$tab}")
            ->assertInertia(fn (Assert $page) => $page->where('tab', $tab)->whereNot($tab, null));
    }
});

test('a state-level holder of audit.view still cannot see the activity tab', function () {
    SpatieRole::findByName('state_director')->givePermissionTo(Permission::AuditView->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($this->world->users['state_director']->fresh())
        ->get("/users/{$this->target->ulid}?tab=activity")
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'profile')->where('tabs', ['profile', 'postings']));
});
