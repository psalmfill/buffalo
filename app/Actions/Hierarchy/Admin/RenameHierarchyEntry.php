<?php

namespace App\Actions\Hierarchy\Admin;

use App\Models\Lga;
use App\Models\SenatorialDistrict;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Renames a district, LGA or ward (and edits an LGA's headquarters). The
 * name stays unique within its parent, case-insensitively, as in the
 * import. Codes never change. Records keep pointing at the same entry, so
 * they show the new name.
 *
 * The official CSV remains the source of truth: a later import with the
 * old name renames it back.
 */
final class RenameHierarchyEntry
{
    /**
     * @throws ValidationException
     */
    public function __invoke(SenatorialDistrict|Lga|Ward $entry, string $name, ?string $headquarters, string $reason, User $actor): SenatorialDistrict|Lga|Ward
    {
        $reason = HierarchyChange::reason($reason);
        $name = HierarchyChange::name($name);

        $changes = ['name' => $name];

        if ($entry instanceof Lga) {
            $changes['headquarters'] = $headquarters === null || trim($headquarters) === '' ? null : HierarchyChange::name($headquarters, 'headquarters');
        }

        [$parentColumn, $parentId] = match (true) {
            $entry instanceof Ward => ['lga_id', $entry->lga_id],
            default => ['state_id', $entry->state_id],
        };

        $taken = $entry->newQuery()
            ->where($parentColumn, $parentId)
            ->whereKeyNot($entry->getKey())
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => sprintf('Another %s here is already named "%s".', mb_strtolower(HierarchyChange::label($entry)), $name)]);
        }

        $before = $entry->only(array_keys($changes));
        $entry->fill($changes);

        if (! $entry->isDirty()) {
            throw ValidationException::withMessages(['name' => 'Nothing has changed.']);
        }

        return DB::transaction(function () use ($entry, $before, $reason, $actor): SenatorialDistrict|Lga|Ward {
            $entry->save();

            HierarchyChange::record($entry, 'renamed', $reason, $actor, ['before' => $before]);

            return $entry;
        });
    }
}
