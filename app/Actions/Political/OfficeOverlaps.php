<?php

namespace App\Actions\Political;

use App\Models\PoliticalOffice;
use Illuminate\Database\Eloquent\Builder;

/**
 * Other current holders of the same single seat (e.g. two current governors
 * of one state), among the offices the user can see. A warning only.
 *
 * Seats: governor and deputy per state, senator per district, chairman and
 * vice per LGA, councillor per ward.
 */
final class OfficeOverlaps
{
    /**
     * @return list<string>
     */
    public function warnings(PoliticalOffice $office): array
    {
        if (! $office->office->isSingleSeat() || ! $office->isCurrent()) {
            return [];
        }

        $query = PoliticalOffice::query()
            ->current()
            ->where('office', $office->office->value)
            ->where('state_id', $office->state_id)
            ->whereKeyNot($office->id)
            ->when($office->office->requiresDistrict(), fn (Builder $q) => $q->where('senatorial_district_id', $office->senatorial_district_id))
            ->when($office->office->requiresWard(), fn (Builder $q) => $q->where('ward_id', $office->ward_id), fn (Builder $q) => $office->office->requiresLga() ? $q->where('lga_id', $office->lga_id) : $q);

        $count = $query->count();

        if ($count === 0) {
            return [];
        }

        return [sprintf(
            '%s other current %s recorded for this seat. Check whether an earlier office should be ended.',
            $count === 1 ? 'There is 1' : "There are {$count}",
            strtolower($office->office->label()).($count === 1 ? '' : 's'),
        )];
    }
}
