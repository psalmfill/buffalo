<?php

use App\Actions\Users\DeactivateUser;
use App\Enums\LoginEvent;
use App\Enums\Permission;
use App\Models\User;
use App\Support\Auth\LoginHistoryRecorder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

test('the audit log is only for audit.view holders', function () {
    $this->actingAs(User::factory()->superAdmin()->create())->get('/audit')->assertOk();
    $this->flushSession();
    $this->actingAs(User::factory()->directorGeneral()->create())->get('/audit')->assertOk();

    foreach (['ddg', 'stateDirector', 'deputyStateDirector', 'lgaChief'] as $state) {
        $this->flushSession();
        $this->actingAs(User::factory()->{$state}()->create())->get('/audit')->assertForbidden();
    }
});

test('login history is only for login_history.view holders', function () {
    $this->actingAs(User::factory()->superAdmin()->create())->get('/login-history')->assertOk();

    foreach (['directorGeneral', 'ddg', 'stateDirector', 'lgaChief'] as $state) {
        $this->flushSession();
        $this->actingAs(User::factory()->{$state}()->create())->get('/login-history')->assertForbidden();
    }
});

test('a state-level role given audit.view or login_history.view is still refused', function () {
    SpatieRole::findByName('state_director')->givePermissionTo([Permission::AuditView->value, Permission::LoginHistoryView->value]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $director = User::factory()->stateDirector()->create();

    expect($director->checkPermissionTo(Permission::AuditView->value))->toBeTrue();

    $this->actingAs($director)->get('/audit')->assertForbidden();
    $this->actingAs($director)->get('/login-history')->assertForbidden();
});

test('the audit log shows old and new values and filters', function () {
    $admin = User::factory()->superAdmin()->create();
    User::factory()->superAdmin()->create();
    $user = User::factory()->directorGeneral()->create(['name' => 'Old Name']);
    $user->update(['name' => 'New Name']);
    app(DeactivateUser::class)($user->fresh(), 'Left', $admin);

    $this->actingAs($admin)->get('/audit?event=updated&subject_type='.urlencode(User::class))
        ->assertInertia(fn (Assert $page) => $page
            ->component('audit/index')
            ->where('entries.data', fn ($entries) => collect($entries)->contains(fn ($e) => ($e['old']['name'] ?? null) === 'Old Name' && ($e['new']['name'] ?? null) === 'New Name')));

    $this->actingAs($admin)->get('/audit?causer='.$admin->ulid.'&event=deactivated')
        ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.properties.reason', 'Left'));
});

test('login history filters and flags repeated failures from one IP', function () {
    $admin = User::factory()->superAdmin()->create();
    $recorder = app(LoginHistoryRecorder::class);

    request()->server->set('REMOTE_ADDR', '10.66.66.66');
    foreach (range(1, 3) as $i) {
        $recorder->record(LoginEvent::LoginFailed, emailAttempted: "guess{$i}@buffalo.test");
    }
    request()->server->set('REMOTE_ADDR', '10.1.1.1');
    $recorder->record(LoginEvent::LoginFailed, emailAttempted: 'typo@buffalo.test');

    $this->actingAs($admin)->get('/login-history?event=login_failed')
        ->assertInertia(fn (Assert $page) => $page
            ->component('audit/login-history')
            ->where('suspiciousIps', ['10.66.66.66'])
            ->where('entries.data', fn ($entries) => collect($entries)->every(
                fn ($e) => $e['repeated_failures'] === ($e['ip_address'] === '10.66.66.66'),
            )));

    $this->actingAs($admin)->get('/login-history?ip=10.1.1.1')->assertInertia(fn (Assert $page) => $page->has('entries.data', 1));
    $this->actingAs($admin)->get('/login-history?email=guess2')->assertInertia(fn (Assert $page) => $page->has('entries.data', 1));
});

test('there are no routes to edit or delete audit data', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'audit') || str_starts_with($route->uri(), 'login-history'));

    expect($routes->flatMap(fn ($route) => $route->methods())->unique()->values()->all())
        ->toEqualCanonicalizing(['GET', 'HEAD']);
});

test('audit entries never expose numeric ids', function () {
    $admin = User::factory()->superAdmin()->create();
    User::factory()->lgaChief()->create(['created_by' => $admin->id]);

    $entries = $this->actingAs($admin)->get('/audit')->viewData('page')['props']['entries']['data'];

    $keys = collect($entries)->flatMap(fn (array $entry) => array_keys(array_merge($entry['old'] ?? [], $entry['new'] ?? [], $entry['properties'])));

    expect($keys)->not->toBeEmpty()
        ->and($keys->filter(fn (string $key) => $key === 'id' || str_ends_with($key, '_id') || str_ends_with($key, '_by'))->all())->toBe([]);
});
