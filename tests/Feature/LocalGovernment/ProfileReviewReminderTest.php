<?php

use App\Models\LgaProfile;
use App\Models\User;
use App\Notifications\LgaProfileReviewDue;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Jurisdiction\JurisdictionWorld;

test('chiefs whose profile was never or not recently reviewed are reminded; nobody else', function () {
    $world = JurisdictionWorld::build();
    Notification::fake();

    // A1a: never reviewed (the world's LGA chief).
    $neverReviewed = $world->users['lga_chief'];
    // A1b: reviewed 200 days ago.
    $stale = User::factory()->lgaChief($world->lgas['A1b'])->create();
    LgaProfile::factory()->forLga($world->lgas['A1b'])->create(['last_reviewed_at' => now()->subDays(200)]);
    // A2: reviewed 10 days ago.
    $fresh = User::factory()->lgaChief($world->lgas['A2'])->create();
    LgaProfile::factory()->forLga($world->lgas['A2'])->create(['last_reviewed_at' => now()->subDays(10)]);
    // B1: due, but its chief is deactivated.
    $inactive = User::factory()->lgaChief($world->lgas['B1'])->inactive()->create();

    $this->artisan('buffalo:profile-review-reminders')->assertSuccessful();

    Notification::assertSentTo($neverReviewed, LgaProfileReviewDue::class, fn ($n) => $n->lga->is($world->lgas['A1a']) && $n->days === 180);
    Notification::assertSentTo($stale, LgaProfileReviewDue::class);
    Notification::assertNotSentTo($fresh, LgaProfileReviewDue::class);
    Notification::assertNotSentTo($inactive, LgaProfileReviewDue::class);
    Notification::assertNotSentTo($world->users['state_director'], LgaProfileReviewDue::class);
    Notification::assertNotSentTo($world->users['director_general'], LgaProfileReviewDue::class);
    Notification::assertCount(2);
});

test('the review period is configurable', function () {
    $world = JurisdictionWorld::build();
    Notification::fake();
    config(['buffalo.profile_review_days' => 7]);
    LgaProfile::factory()->forLga($world->lgas['A1a'])->create(['last_reviewed_at' => now()->subDays(10)]);

    $this->artisan('buffalo:profile-review-reminders')->assertSuccessful();

    Notification::assertSentTo($world->users['lga_chief'], LgaProfileReviewDue::class, fn ($n) => $n->days === 7
        && $n->toArray($world->users['lga_chief'])['url'] === '/lgas/'.$world->lgas['A1a']->ulid.'?tab=profile');
});

test('the reminder is scheduled weekly', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'buffalo:profile-review-reminders'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 7 * * 1');
});
