<?php

namespace Database\Factories;

use App\Models\State;
use App\Models\StateProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StateProfile>
 */
class StateProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'state_id' => State::factory(),
            'terrain' => [],
        ];
    }

    public function forState(State $state): static
    {
        return $this->state(['state_id' => $state->id]);
    }
}
