<?php

namespace App\Actions\Hierarchy\Admin;

use App\Actions\Hierarchy\HierarchyRules;
use App\Models\State;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits a state's capital from Administration › Hierarchy. The 37 states
 * themselves are fixed: no create, rename or delete. (State profile editors
 * may also change the capital on the state page, Phase 11F.)
 */
final class ChangeStateCapital
{
    /**
     * @throws ValidationException
     */
    public function __invoke(State $state, ?string $capital, string $reason, User $actor): State
    {
        $reason = HierarchyChange::reason($reason);
        $capital = $capital === null || trim($capital) === '' ? null : trim($capital);

        if ($capital !== null && mb_strlen($capital) > HierarchyRules::MAX_NAME_LENGTH) {
            throw ValidationException::withMessages(['capital' => 'The capital may be at most '.HierarchyRules::MAX_NAME_LENGTH.' characters.']);
        }

        if ($capital === $state->capital) {
            throw ValidationException::withMessages(['capital' => 'Nothing has changed.']);
        }

        $before = $state->capital;

        return DB::transaction(function () use ($state, $capital, $before, $reason, $actor): State {
            $state->forceFill(['capital' => $capital])->save();

            HierarchyChange::record($state, 'capital_changed', $reason, $actor, ['before' => $before, 'after' => $capital]);

            return $state;
        });
    }
}
