<?php

namespace App\Console\Commands;

use App\Actions\Blackspots\GenerateBlackspotSuggestions;
use App\Enums\Role;
use App\Models\State;
use App\Notifications\BlackspotSuggestionsReady;
use App\Support\Notifications\NotifyRole;
use Illuminate\Console\Command;

/**
 * Nightly (Phase 6C): regenerates blackspot suggestions for every state (or
 * one, with --state=<code>) in the system context, and tells each State
 * Director how many new ones appeared (counts only).
 */
class SuggestBlackspots extends Command
{
    protected $signature = 'buffalo:suggest-blackspots {--state= : A state code}';

    protected $description = 'Suggest blackspots from clusters of recent incidents';

    public function handle(GenerateBlackspotSuggestions $generate, NotifyRole $notify): int
    {
        $states = State::query()
            ->when($this->option('state'), fn ($q, $code) => $q->where('code', strtoupper((string) $code)))
            ->orderBy('code')->get();

        $total = 0;

        foreach ($states as $state) {
            $result = $generate($state->id);
            $total += $result['new'];

            if ($result['new'] > 0) {
                $notify->send(new BlackspotSuggestionsReady($state->name, $result['new']), Role::StateDirector, $state);
            }
        }

        $this->components->info("New blackspot suggestions: {$total}.");

        return self::SUCCESS;
    }
}
