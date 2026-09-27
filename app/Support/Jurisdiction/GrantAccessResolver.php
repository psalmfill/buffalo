<?php

namespace App\Support\Jurisdiction;

use App\Models\Person;
use App\Models\RecordAccessGrant;
use App\Models\User;
use App\Support\Jurisdiction\Contracts\AccessGrantResolver;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Cross-jurisdiction access grants (Phase 3C, CLAUDE.md section 4 rule 8).
 *
 * Grants exist for persons only. The jurisdiction scope calls
 * addGrantedRecords() INSIDE its nested group, so a granted person is
 * "in jurisdiction" for the holder while the grant is active. The
 * classification scope is untouched: a grant never overrides
 * classification.
 *
 * Nothing else becomes visible through a grant: a person's POI record,
 * offices and group memberships are shown on the person page by
 * GrantedPersonView, per the grant's scope.
 */
final class GrantAccessResolver implements AccessGrantResolver
{
    /**
     * @param  Builder<covariant Model>  $query
     */
    public function addGrantedRecords(Builder $query, Model&JurisdictionScoped $model, User $user): void
    {
        if (! $model instanceof Person) {
            return;
        }

        $query->orWhereIn($model->qualifyColumn('id'), RecordAccessGrant::query()
            ->active()
            ->where('user_id', $user->id)
            ->where('subject_type', $model->getMorphClass())
            ->select('subject_id'));
    }

    public function hasGrant(User $user, Model&JurisdictionScoped $record): bool
    {
        return $record instanceof Person && $this->activeGrant($user, $record) !== null;
    }

    public function activeGrant(User $user, Person $person): ?RecordAccessGrant
    {
        return RecordAccessGrant::query()
            ->active()
            ->where('user_id', $user->id)
            ->where('subject_type', $person->getMorphClass())
            ->where('subject_id', $person->id)
            ->latest('expires_at')
            ->first();
    }
}
