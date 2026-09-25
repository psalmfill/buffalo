<?php

use App\Enums\NotificationSeverity;
use App\Enums\Role;
use App\Models\GeopoliticalZone;
use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use App\Notifications\BuffaloNotification;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Notifications\NotifyRole;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;

/**
 * A minimal notification for these tests.
 */
class TestAlertNotification extends BuffaloNotification
{
    public function __construct(private readonly bool $mail = false, private readonly ?string $path = '/dashboard') {}

    public function type(): string
    {
        return 'test_alert';
    }

    public function title(User $notifiable): string
    {
        return 'Test alert';
    }

    public function body(User $notifiable): string
    {
        return 'Something happened.';
    }

    public function url(User $notifiable): ?string
    {
        return $this->path;
    }

    public function severity(): NotificationSeverity
    {
        return NotificationSeverity::Critical;
    }

    public function allowsMail(): bool
    {
        return $this->mail;
    }
}

test('notifications use database and broadcast, and mail only when enabled and allowed', function () {
    $user = User::factory()->directorGeneral()->create();

    config(['buffalo.mail_enabled' => false]);
    expect((new TestAlertNotification(mail: true))->via($user))->toBe(['database', 'broadcast']);

    config(['buffalo.mail_enabled' => true]);
    expect((new TestAlertNotification(mail: true))->via($user))->toBe(['database', 'broadcast', 'mail'])
        ->and((new TestAlertNotification(mail: false))->via($user))->toBe(['database', 'broadcast']);
});

test('the payload has exactly the documented fields', function () {
    $user = User::factory()->directorGeneral()->create();

    $payload = (new TestAlertNotification)->toArray($user);

    expect(array_keys($payload))->toBe(['type', 'title', 'body', 'url', 'severity', 'created_at'])
        ->and($payload['severity'])->toBe('critical')
        ->and($payload['url'])->toBe('/dashboard');
});

test('notification URLs must be internal paths', function (string $url) {
    (new TestAlertNotification(path: $url))->toArray(User::factory()->directorGeneral()->create());
})->throws(InvalidArgumentException::class)->with(['https://example.com/x', '//evil.test/x', 'dashboard']);

test('every notification extends BuffaloNotification and is queued and encrypted', function () {
    $classes = collect(File::files(app_path('Notifications')))
        ->map(fn ($file) => 'App\\Notifications\\'.$file->getFilenameWithoutExtension())
        ->reject(fn (string $class) => $class === BuffaloNotification::class);

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        expect(is_subclass_of($class, BuffaloNotification::class))->toBeTrue("{$class} must extend BuffaloNotification")
            ->and(is_subclass_of($class, ShouldQueue::class))->toBeTrue()
            ->and(is_subclass_of($class, ShouldBeEncrypted::class))->toBeTrue();
    }
});

test('a sent notification is stored for the user and broadcast on users.{ulid}', function () {
    $user = User::factory()->directorGeneral()->create();

    $user->notify(new TestAlertNotification);

    expect($user->notifications()->sole()->data['title'])->toBe('Test alert')
        ->and($user->receivesBroadcastNotificationsOn())->toBe('users.'.$user->ulid);
});

test('the users.{ulid} channel authorises only its owner', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);

    // Channels are registered on the broadcaster created at boot (the null
    // driver in tests): register them on the real Reverb driver.
    Broadcast::purge();
    require base_path('routes/channels.php');

    $owner = User::factory()->directorGeneral()->create();
    $other = User::factory()->directorGeneral()->create();

    $this->actingAs($owner)
        ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-users.'.$owner->ulid])
        ->assertOk()
        ->assertJsonStructure(['auth']);

    $this->actingAs($owner)
        ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-users.'.$other->ulid])
        ->assertForbidden();

    $this->actingAs($owner)
        ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-App.Models.User.'.$owner->id])
        ->assertForbidden();
});

test('NotifyRole targets exactly the active users of a role in a jurisdiction', function () {
    Notification::fake();

    $zoneA = GeopoliticalZone::factory()->create();
    $zoneB = GeopoliticalZone::factory()->create();
    $stateA = State::factory()->for($zoneA)->create();
    $stateB = State::factory()->for($zoneB)->create();
    $lgaA1 = Lga::factory()->create(['state_id' => $stateA->id]);
    $lgaA2 = Lga::factory()->create(['state_id' => $stateA->id]);
    $lgaB = Lga::factory()->create(['state_id' => $stateB->id]);

    $sdA = User::factory()->stateDirector($stateA)->create();
    User::factory()->stateDirector($stateB)->create();
    User::factory()->stateDirector($stateA)->inactive()->create();
    $chiefA1 = User::factory()->lgaChief($lgaA1)->create();
    $chiefA2 = User::factory()->lgaChief($lgaA2)->create();
    User::factory()->lgaChief($lgaB)->create();
    $ddgNational = User::factory()->ddg()->create();
    $ddgA = User::factory()->ddg([$zoneA])->create();
    User::factory()->ddg([$zoneB])->create();
    $admins = User::factory()->superAdmin()->count(2)->create();
    User::factory()->superAdmin()->inactive()->create();

    // Sent from an LGA chief's request: recipients do not depend on the sender's view.
    app(JurisdictionContext::class)->user($chiefA1);
    $notify = app(NotifyRole::class);

    $cases = [
        [Role::StateDirector, $stateA, [$sdA]],
        [Role::StateDirector, $lgaA2, [$sdA]],
        [Role::LgaSecurityChief, $lgaA1, [$chiefA1]],
        [Role::LgaSecurityChief, $stateA, [$chiefA1, $chiefA2]],
        [Role::DeputyDirectorGeneral, $stateA, [$ddgNational, $ddgA]],
        [Role::SuperAdmin, null, $admins->all()],
        [Role::SuperAdmin, $stateB, $admins->all()],
    ];

    foreach ($cases as [$role, $place, $expected]) {
        Notification::fake();

        $count = $notify->send(new TestAlertNotification, $role, $place);

        $expectedIds = collect($expected)->map->id->sort()->values()->all();
        $sentIds = collect(Notification::sentNotifications())->keys()
            ->flatMap(fn ($type) => collect(Notification::sentNotifications()[$type])->keys())
            ->map(fn ($id) => (int) $id)->sort()->values()->all();

        expect($count)->toBe(count($expected))
            ->and($sentIds)->toBe($expectedIds, "{$role->value}");
    }
});
