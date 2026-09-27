<?php

namespace App\Console\Commands;

use App\Models\RecordMatchEvent;
use App\Models\State;
use App\Notifications\MatchDigest;
use App\Support\Jurisdiction\AccessRequestApprovers;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Daily (Phase 3C): tells each State Director (and delegated deputy) how
 * many of their state's records produced hits for officers in the last 24
 * hours, by requesting state and officer. The records are listed, as
 * links, on the Activity tab of /access-requests.
 */
class SendMatchDigest extends Command
{
    protected $signature = 'buffalo:match-digest';

    protected $description = 'Send State Directors the daily digest of cross-jurisdiction hits on their records';

    public function handle(AccessRequestApprovers $approvers): int
    {
        $events = RecordMatchEvent::query()
            ->with(['user.state'])
            ->where('suppressed', false)
            ->where('created_at', '>=', CarbonImmutable::now()->subDay())
            ->get();

        $sent = 0;

        foreach ($events->groupBy('owning_state_id') as $stateId => $stateEvents) {
            $state = State::query()->find($stateId);

            if ($state === null) {
                continue;
            }

            $lines = array_values($stateEvents
                ->groupBy(fn (RecordMatchEvent $e) => $e->user->state !== null ? $e->user->state->name.' State' : 'National')
                ->map(fn ($byState, string $place) => "{$place}: {$byState->count()} (".$byState->groupBy(fn (RecordMatchEvent $e) => $e->user->name)
                    ->map(fn ($byOfficer, string $officer) => "{$officer} {$byOfficer->count()}")->implode(', ').')')
                ->values()->all());

            $owners = $approvers->stateOwners((int) $stateId);
            Notification::send($owners, new MatchDigest($state->name, $stateEvents->count(), $lines));
            $sent += $owners->count();
        }

        $this->components->info("Match digests sent: {$sent}.");

        return self::SUCCESS;
    }
}
