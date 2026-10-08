<?php

namespace App\Support\Persons\Merge;

use App\Models\Person;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Locks for a merge or its undo (Phase 10A.5), inside the caller's
 * transaction:
 *
 * 1. buffalo.person_merge = the merge ULID (transaction-local): the only
 *    way the incident_persons lock trigger lets rows of closed or invalid
 *    incidents be repointed (see the 10A migration);
 * 2. a transaction-level advisory lock per person ID, in ID order, so two
 *    merges or undos involving the same person run one after the other;
 * 3. row locks (FOR UPDATE) on both persons, in ID order, so an edit of
 *    either waits until the merge has committed.
 *
 * The persons are then read again: the caller re-checks them, since a merge
 * that waited may find a record already merged.
 */
final class PersonMergeLocks
{
    /** Advisory lock class id for persons (pg_advisory_xact_lock(int, int)). */
    private const LOCK_CLASS = 10_001;

    /**
     * @return array{0: Person, 1: Person} the survivor and the source, re-read
     *
     * @throws MergeBlocked when the other merge holds the locks for too long
     */
    public static function lock(Person $survivor, Person $source, string $mergeUlid): array
    {
        DB::statement("SELECT set_config('buffalo.person_merge', ?, true)", [$mergeUlid]);
        DB::statement("SELECT set_config('lock_timeout', ?, true)", [(string) config('buffalo.merges.lock_timeout', '10s')]);

        $ids = [$survivor->id, $source->id];
        sort($ids);

        try {
            foreach ($ids as $id) {
                DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [self::LOCK_CLASS, $id]);
            }

            $rows = Person::withTrashed()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['55P03', '40P01'], true)) {
                throw new MergeBlocked('Another merge or edit of these records is in progress. Try again in a moment.');
            }

            throw $e;
        }

        $lockedSurvivor = $rows->get($survivor->id);
        $lockedSource = $rows->get($source->id);

        if (! $lockedSurvivor instanceof Person || ! $lockedSource instanceof Person) {
            throw new MergeBlocked('One of the records is no longer available.');
        }

        return [$lockedSurvivor, $lockedSource];
    }
}
