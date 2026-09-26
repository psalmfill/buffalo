<?php

namespace App\Actions\CriminalGroups;

use App\Models\CriminalGroup;
use App\Models\CriminalGroupArea;
use App\Models\Lga;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Adds and removes a group's areas of operation (any LGA, in any state).
 * Areas describe the group; they never grant visibility of it.
 */
final class ManageGroupAreas
{
    public function add(CriminalGroup $group, Lga $lga, ?string $notes, User $actor): CriminalGroupArea
    {
        $area = new CriminalGroupArea(['notes' => $notes]);
        $area->forceFill([
            'criminal_group_id' => $group->id,
            'lga_id' => $lga->id,
            'state_id' => $lga->state_id,
            'created_by' => $actor->id,
        ])->save();

        AuditLog::event('area_added', "Area of operation added: {$lga->name} LGA", $group, ['lga' => $lga->ulid], $actor);

        return $area;
    }

    public function remove(CriminalGroupArea $area, User $actor): void
    {
        DB::transaction(function () use ($area, $actor): void {
            $lga = $area->lga;
            AuditLog::event('area_removed', "Area of operation removed: {$lga->name} LGA", $area->group, ['lga' => $lga->ulid], $actor);
            $area->delete();
        });
    }
}
