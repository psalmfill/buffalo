<?php

use App\Models\User;
use App\Notifications\TwoFactorResetNotification;
use Inertia\Testing\AssertableInertia as Assert;

function notified(User $user, int $count = 1): User
{
    foreach (range(1, $count) as $i) {
        $user->notify(new TwoFactorResetNotification);
    }

    return $user;
}

test('the notifications page lists the user\'s notifications with a read filter', function () {
    $user = notified(User::factory()->directorGeneral()->create(), 3);
    $user->notifications()->first()->markAsRead();
    notified(User::factory()->directorGeneral()->create());

    $this->actingAs($user)->get('/notifications')->assertInertia(fn (Assert $page) => $page
        ->component('notifications/index')
        ->has('notifications.data', 3)
        ->where('unreadCount', 2)
        ->where('notifications.data.0.severity', 'warning'));

    $this->actingAs($user)->get('/notifications?filter=unread')->assertInertia(fn (Assert $page) => $page->has('notifications.data', 2));
    $this->actingAs($user)->get('/notifications?filter=read')->assertInertia(fn (Assert $page) => $page->has('notifications.data', 1));
});

test('the bell feed returns the latest 10 and the unread count', function () {
    $user = notified(User::factory()->directorGeneral()->create(), 12);

    $this->actingAs($user)->getJson('/notifications/recent')
        ->assertOk()
        ->assertJsonCount(10, 'notifications')
        ->assertJsonPath('unreadCount', 12)
        ->assertJsonStructure(['notifications' => [['id', 'type', 'title', 'body', 'url', 'severity', 'read_at', 'created_at']]]);
});

test('a notification can be marked as read only by its owner', function () {
    $owner = notified(User::factory()->directorGeneral()->create());
    $other = User::factory()->directorGeneral()->create();
    $id = $owner->notifications()->first()->id;

    $this->actingAs($other)->postJson("/notifications/{$id}/read")->assertNotFound();
    expect($owner->unreadNotifications()->count())->toBe(1);

    $this->flushSession();
    $this->actingAs($owner)->postJson("/notifications/{$id}/read")->assertOk();
    expect($owner->unreadNotifications()->count())->toBe(0);
});

test('mark all as read affects only the current user', function () {
    $user = notified(User::factory()->directorGeneral()->create(), 3);
    $other = notified(User::factory()->directorGeneral()->create(), 2);

    $this->actingAs($user)->postJson('/notifications/read-all')->assertOk()->assertJson(['unreadCount' => 0]);

    expect($user->unreadNotifications()->count())->toBe(0)
        ->and($other->unreadNotifications()->count())->toBe(2);
});

test('the unread count is shared with every page', function () {
    $user = notified(User::factory()->directorGeneral()->create(), 2);

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('notifications.unreadCount', 2));
});

test('guests cannot reach notifications', function () {
    $this->get('/notifications')->assertRedirect(route('login'));
    $this->getJson('/notifications/recent')->assertUnauthorized();
});
