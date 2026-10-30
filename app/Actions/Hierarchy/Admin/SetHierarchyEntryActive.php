<?php

namespace App\Actions\Hierarchy\Admin;

use App\Models\Lga;
use App\Models\SenatorialDistrict;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deactivates or reactivates a district, LGA or ward. Nothing is ever
 * deleted: records that already point at a deactivated entry keep it and
 * still show its name; it is only left out of pickers and new records.
 *
 * - A district or LGA with active children cannot be deactivated
 *   (deactivate its LGAs or wards first), so no active entry ever sits
 *   under a deactivated one.
 * - Likewise an entry can be reactivated only under an active parent.
 */
final class SetHierarchyEntryActive
{
    /**
     * @throws ValidationException
     */
    public function __invoke(SenatorialDistrict|Lga|Ward $entry, bool $active, string $reason, User $actor): SenatorialDistrict|Lga|Ward
    {
        $reason = HierarchyChange::reason($reason);
        $label = HierarchyChange::label($entry);

        if ($entry->is_active === $active) {
            throw ValidationException::withMessages(['entry' => $active ? "{$label} {$entry->name} is already active." : "{$label} {$entry->name} is already deactivated."]);
        }

        $active ? $this->checkParent($entry) : $this->checkChildren($entry);

        return DB::transaction(function () use ($entry, $active, $reason, $actor): SenatorialDistrict|Lga|Ward {
            $entry->forceFill(['is_active' => $active])->save();

            HierarchyChange::record($entry, $active ? 'reactivated' : 'deactivated', $reason, $actor);

            return $entry;
        });
    }

    private function checkChildren(SenatorialDistrict|Lga|Ward $entry): void
    {
        $children = match (true) {
            $entry instanceof SenatorialDistrict => [$entry->lgas()->where('is_active', true)->count(), 'LGA', 'LGAs'],
            $entry instanceof Lga => [$entry->wards()->where('is_active', true)->count(), 'ward', 'wards'],
            default => [0, '', ''],
        };

        if ($children[0] > 0) {
            throw ValidationException::withMessages(['entry' => sprintf(
                '%s still has %d active %s. Deactivate %s first.',
                $entry->name,
                $children[0],
                $children[0] === 1 ? $children[1] : $children[2],
                $children[0] === 1 ? 'it' : 'them',
            )]);
        }
    }

    private function checkParent(SenatorialDistrict|Lga|Ward $entry): void
    {
        $parent = match (true) {
            $entry instanceof Lga => $entry->senatorialDistrict,
            $entry instanceof Ward => $entry->lga,
            default => null,
        };

        if ($parent !== null && ! $parent->is_active) {
            throw ValidationException::withMessages(['entry' => sprintf('%s %s is deactivated; reactivate it first.', HierarchyChange::label($parent), $parent->name)]);
        }
    }
}
