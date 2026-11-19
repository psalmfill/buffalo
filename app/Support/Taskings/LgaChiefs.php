<?php

namespace App\Support\Taskings;

use App\Enums\Role;
use App\Models\OfficerPosting;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The current Security Chief of each LGA (Phase 11H): the user of the
 * LGA's open LGA-chief officer posting, if their account is active. A
 * tasking is addressed to the LGA, not to this person: who SEES it is
 * decided by the scopes (any LGA chief of that LGA); this only names the
 * chief in the picker, warns about vacant LGAs and addresses notifications.
 *
 * Postings are read through their jurisdiction scope: the sender's state
 * (or the system context in jobs).
 */
final class LgaChiefs
{
    /**
     * @param  iterable<int>  $lgaIds
     * @return Collection<int, User> keyed by LGA id; vacant LGAs are absent
     */
    public function forLgas(iterable $lgaIds): Collection
    {
        $ids = collect($lgaIds)->map(fn (mixed $id) => (int) $id)->unique()->values()->all();

        if ($ids === []) {
            return collect();
        }

        /** @var Collection<int, User> */
        return OfficerPosting::query()
            ->with('user')
            ->where('role', Role::LgaSecurityChief->value)
            ->whereIn('lga_id', $ids)
            ->whereNull('ended_at')
            ->orderBy('started_at')
            ->get()
            ->filter(fn (OfficerPosting $p) => $p->user !== null && $p->user->is_active && $p->user->lga_id === $p->lga_id)
            ->keyBy('lga_id')
            ->map(fn (OfficerPosting $p) => $p->user);
    }
}
