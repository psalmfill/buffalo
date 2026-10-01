<?php

namespace App\Support\Jurisdiction;

use App\Models\RecordDissemination;
use App\Models\User;
use App\Support\Jurisdiction\Contracts\AccessGrantResolver;
use App\Support\Jurisdiction\Contracts\Disseminable;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Dissemination (Phase 7A, CLAUDE.md section 4 rule 8): a threat report or
 * flashpoint pushed by its owning state to another state or LGA is "in
 * jurisdiction" for the recipients while the dissemination is active.
 *
 * Only models implementing Disseminable (ThreatReport and Flashpoint,
 * enforced by an architecture test) are affected. The classification scope
 * is untouched: a dissemination never overrides classification. Write
 * abilities use JurisdictionGate::canAccessOwn(), so recipients only read.
 *
 * Reads record_disseminations through the query builder (not the model) so
 * nothing re-enters the jurisdiction scope.
 */
final class DisseminationResolver implements AccessGrantResolver
{
    /**
     * @param  Builder<covariant Model>  $query
     */
    public function addGrantedRecords(Builder $query, Model&JurisdictionScoped $model, User $user): void
    {
        if (! $model instanceof Disseminable) {
            return;
        }

        $query->orWhereIn($model->qualifyColumn('id'), $this->received($user, $model->disseminationType())->select('subject_id'));
    }

    public function hasGrant(User $user, Model&JurisdictionScoped $record): bool
    {
        return $record instanceof Disseminable
            && $this->received($user, $record->disseminationType())->where('subject_id', $record->getKey())->exists();
    }

    /**
     * Ids of the records of this type the user receives (for list markers).
     *
     * @return list<int>
     */
    public function receivedIds(User $user, string $type): array
    {
        return array_values(array_map('intval', $this->received($user, $type)->distinct()->pluck('subject_id')->all()));
    }

    /**
     * Narrow a scoped query to records the user sees ONLY through a
     * dissemination ("Shared with us" filters).
     *
     * @template TModel of Model&Disseminable
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function sharedOnly(Builder $query, User $user): Builder
    {
        /** @var TModel $model */
        $model = $query->getModel();
        $own = $model->newQuery()->select($model->qualifyColumn('id'));
        app(JurisdictionScope::class)->applyOwn($own, $model, $user);

        return $query->whereIn($model->qualifyColumn('id'), $this->received($user, $model->disseminationType())->select('subject_id'))
            ->whereNotIn($model->qualifyColumn('id'), $own);
    }

    /**
     * For list markers: whether each record is seen only through a
     * dissemination.
     *
     * @param  iterable<Model&Disseminable>  $records
     * @return array<int, true> record id => true
     */
    public function sharedMarkers(iterable $records, User $user): array
    {
        $gate = app(JurisdictionGate::class);
        $received = null;
        $marks = [];

        foreach ($records as $record) {
            $received ??= array_flip($this->receivedIds($user, $record->disseminationType()));

            if (isset($received[(int) $record->getKey()]) && ! $gate->canAccessOwn($user, $record)) {
                $marks[(int) $record->getKey()] = true;
            }
        }

        return $marks;
    }

    private function received(User $user, string $type): QueryBuilder
    {
        $query = DB::table('record_disseminations')
            ->where('record_disseminations.subject_type', $type)
            ->whereNull('record_disseminations.revoked_at')
            ->where('record_disseminations.expires_at', '>', CarbonImmutable::now());

        RecordDissemination::applyReceivedBy($query, $user);

        return $query;
    }
}
