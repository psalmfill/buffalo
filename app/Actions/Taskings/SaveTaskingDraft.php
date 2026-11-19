<?php

namespace App\Actions\Taskings;

use App\Enums\Classification;
use App\Enums\TaskingPriority;
use App\Models\State;
use App\Models\Tasking;
use App\Models\User;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Creates or updates a tasking DRAFT (Phase 11H). The chosen LGAs are kept
 * on the draft (`draft_lga_ids`); recipient rows are created only on send.
 * A sent tasking is never edited (also refused by a database trigger).
 *
 * @phpstan-type Values array{title: ?string, body: ?string, priority: TaskingPriority, response_due_at: ?CarbonImmutable, classification: Classification, lga_ids: list<int>}
 */
final class SaveTaskingDraft
{
    /**
     * @param  Values  $values
     */
    public function create(State $state, array $values, User $actor): Tasking
    {
        $tasking = new Tasking;
        $tasking->forceFill(['state_id' => $state->id, 'created_by' => $actor->id]);

        return $this->fill($tasking, $values, $actor);
    }

    /**
     * @param  Values  $values
     */
    public function update(Tasking $tasking, array $values, User $actor): Tasking
    {
        if (! $tasking->isDraft()) {
            throw new InvalidArgumentException('A sent tasking cannot be changed.');
        }

        return $this->fill($tasking, $values, $actor);
    }

    /**
     * @param  Values  $values
     */
    private function fill(Tasking $tasking, array $values, User $actor): Tasking
    {
        $tasking->forceFill([
            'title' => $values['title'],
            'body' => $values['body'],
            'priority' => $values['priority'],
            'response_due_at' => $values['response_due_at'],
            'classification' => $values['classification'],
            'draft_lga_ids' => array_values(array_unique($values['lga_ids'])),
            'updated_by' => $actor->id,
        ])->save();

        return $tasking;
    }
}
