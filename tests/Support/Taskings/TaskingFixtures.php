<?php

namespace Tests\Support\Taskings;

use App\Actions\Taskings\SendTasking;
use App\Actions\Users\OfficerPostings;
use App\Models\Lga;
use App\Models\State;
use App\Models\Tasking;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionContext;
use Tests\Support\CrossJurisdiction\CrossFixtures;

/**
 * Taskings for tests (Phase 11H): chiefs with an open officer posting (the
 * LGA's "current chief"), drafts from the factory, and sent taskings
 * through the SendTasking action, as the sender.
 */
final class TaskingFixtures
{
    /** An LGA Security Chief with an open posting for $lga. */
    public static function chief(Lga $lga): User
    {
        CrossFixtures::system();
        $chief = User::factory()->lgaChief($lga)->create();
        app(OfficerPostings::class)->open($chief);

        return $chief;
    }

    /** A State Director with an open posting for $state. */
    public static function director(State $state): User
    {
        CrossFixtures::system();
        $director = User::factory()->stateDirector($state)->create();
        app(OfficerPostings::class)->open($director);

        return $director;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function draft(User $sender, array $attributes = []): Tasking
    {
        CrossFixtures::system();

        return Tasking::factory()->inState((int) $sender->state_id)->create(['created_by' => $sender->id, ...$attributes]);
    }

    /**
     * A tasking sent by $sender to $lgas.
     *
     * @param  list<Lga>  $lgas
     * @param  array<string, mixed>  $attributes
     */
    public static function sent(User $sender, array $lgas, array $attributes = []): Tasking
    {
        $tasking = self::draft($sender, $attributes);
        $sent = app(JurisdictionContext::class)->actingAs($sender, fn () => app(SendTasking::class)($tasking, array_map(fn (Lga $l) => $l->id, $lgas), $sender));
        CrossFixtures::system();

        return $sent;
    }
}
