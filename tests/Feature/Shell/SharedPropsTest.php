<?php

use App\Enums\Permission;
use App\Models\GeopoliticalZone;
use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/**
 * Every key path in $data named "id" or ending in "_id".
 *
 * @return list<string>
 */
function numericIdKeys(mixed $data, string $path = ''): array
{
    if (! is_array($data)) {
        return [];
    }

    $found = [];

    foreach ($data as $key => $value) {
        $here = $path === '' ? (string) $key : "{$path}.{$key}";

        if (is_string($key) && ($key === 'id' || str_ends_with($key, '_id'))) {
            $found[] = $here;
        }

        array_push($found, ...numericIdKeys($value, $here));
    }

    return $found;
}

/**
 * Shared props only (page props minus the page's own).
 *
 * @return array<string, mixed>
 */
function sharedProps(TestResponse $response): array
{
    return collect($response->viewData('page')['props'])->only(['app', 'auth', 'notifications', 'sidebarOpen', 'errors'])->all();
}

test('shared props contain no numeric ids, for any role', function (string $who) {
    $world = JurisdictionWorld::build();

    $response = $this->actingAs($world->users[$who])->get('/notifications')->assertOk();

    expect(numericIdKeys(sharedProps($response)))->toBe([])
        ->and(numericIdKeys($response->viewData('page')['props']))->toBe([]);
})->with(['super_admin', 'director_general', 'ddg_national', 'ddg_zone_a', 'state_director', 'deputy_state_director', 'lga_chief']);

test('guest pages share app data and no user', function () {
    $response = $this->get('/login')->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->where('auth.user', null)
        ->where('auth.permissions', [])
        ->where('app.name', config('app.name'))
        ->where('app.sessionIdleMinutes', (int) config('session.lifetime'))
        ->has('app.banner'));

    expect(numericIdKeys($response->viewData('page')['props']))->toBe([]);
});

test('auth.user carries role, jurisdiction and label', function (Closure $make, array $expected) {
    $user = $make();

    $this->actingAs($user)->get('/notifications')->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.ulid', $user->ulid)
        ->where('auth.user.name', $user->name)
        ->where('auth.user.email', $user->email)
        ->where('auth.user.role.value', $expected['role'])
        ->where('auth.user.jurisdiction.level', $expected['level'])
        ->where('auth.user.jurisdiction.zones', $expected['zones'])
        ->where('auth.user.jurisdictionLabel', $expected['label'])
        ->where('auth.user.jurisdiction.state', fn ($state) => data_get($state, 'name') === $expected['state'])
        ->where('auth.user.jurisdiction.lga', fn ($lga) => data_get($lga, 'name') === $expected['lga']));
})->with([
    'super admin' => [fn () => User::factory()->superAdmin()->create(), ['role' => 'super_admin', 'level' => 'system', 'zones' => [], 'label' => 'System', 'state' => null, 'lga' => null]],
    'director general' => [fn () => User::factory()->directorGeneral()->create(), ['role' => 'director_general', 'level' => 'national', 'zones' => [], 'label' => 'National', 'state' => null, 'lga' => null]],
    'zoned DDG' => [fn () => User::factory()->ddg([
        GeopoliticalZone::factory()->create(['name' => 'North West']),
        GeopoliticalZone::factory()->create(['name' => 'North East']),
    ])->create(), ['role' => 'deputy_director_general', 'level' => 'national', 'zones' => ['North East', 'North West'], 'label' => 'North East Zone, North West Zone', 'state' => null, 'lga' => null]],
    'state director' => [fn () => User::factory()->stateDirector(State::factory()->create(['name' => 'Lagos']))->create(), ['role' => 'state_director', 'level' => 'state', 'zones' => [], 'label' => 'Lagos State', 'state' => 'Lagos', 'lga' => null]],
    'LGA chief' => [fn () => User::factory()->lgaChief(Lga::factory()->create([
        'name' => 'Ikeja',
        'state_id' => State::factory()->create(['name' => 'Lagos'])->id,
    ]))->create(), ['role' => 'lga_security_chief', 'level' => 'lga', 'zones' => [], 'label' => 'Ikeja LGA, Lagos State', 'state' => 'Lagos', 'lga' => 'Ikeja']],
    'FCT state director' => [fn () => User::factory()->stateDirector(State::factory()->create(['name' => 'Federal Capital Territory']))->create(), ['role' => 'state_director', 'level' => 'state', 'zones' => [], 'label' => 'Federal Capital Territory', 'state' => 'Federal Capital Territory', 'lga' => null]],
]);

test('auth.permissions lists exactly the permissions of the role', function (string $role) {
    $user = User::factory()->create();
    $user->syncRoles([$role]);

    $expected = collect(RolesAndPermissionsSeeder::permissionsByRole()[$role])->sort()->values()->all();

    $this->actingAs($user->fresh())->get('/notifications')
        ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', $expected));
})->with(array_keys(RolesAndPermissionsSeeder::permissionsByRole()));

test('the frontend permission list mirrors the Permission enum', function () {
    $source = file_get_contents(resource_path('js/types/auth.ts'));
    preg_match('/export const PERMISSIONS = \[(.*?)\] as const;/s', (string) $source, $match);
    preg_match_all("/'([a-z_.]+)'/", $match[1] ?? '', $names);

    expect($names[1])->toEqualCanonicalizing(array_map(fn (Permission $p) => $p->value, Permission::cases()));
});

test('the navigation test uses the real role permissions', function () {
    $source = (string) file_get_contents(resource_path('js/config/navigation.test.ts'));
    preg_match('/const ROLE_PERMISSIONS[^=]*= \{(.*?)\n\};/s', $source, $block);
    preg_match_all('/(\w+): \[(.*?)\]/s', $block[1] ?? '', $roles, PREG_SET_ORDER);

    $fromTest = collect($roles)->mapWithKeys(function (array $match): array {
        preg_match_all("/'([a-z_.]+)'/", $match[2], $names);

        return [$match[1] => collect($names[1])->sort()->values()->all()];
    })->all();

    $fromSeeder = collect(RolesAndPermissionsSeeder::permissionsByRole())
        ->map(fn (array $permissions) => collect($permissions)->sort()->values()->all())
        ->all();

    expect($fromTest)->toEqual($fromSeeder);
});
