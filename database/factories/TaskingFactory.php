<?php

namespace Database\Factories;

use App\Enums\Classification;
use App\Enums\TaskingPriority;
use App\Enums\TaskingStatus;
use App\Models\State;
use App\Models\Tasking;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tasking DRAFTS (Phase 11H). Sent taskings are made with the SendTasking
 * action (recipients, reference and status in one transaction; the
 * database refuses a sent tasking without recipients).
 *
 * @extends Factory<Tasking>
 */
class TaskingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'state_id' => State::factory(),
            'title' => 'Patrol the market roads',
            'body' => '<p>Increase patrols on the market roads this week and report back.</p>',
            'priority' => TaskingPriority::Routine,
            'classification' => Classification::Restricted,
            'status' => TaskingStatus::Draft,
        ];
    }

    public function inState(State|int $state): static
    {
        return $this->state(['state_id' => $state instanceof State ? $state->id : $state]);
    }
}
