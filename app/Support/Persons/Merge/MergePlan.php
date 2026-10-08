<?php

namespace App\Support\Persons\Merge;

use App\Enums\AccessRequestStatus;
use App\Enums\Classification;
use App\Models\Lga;
use App\Models\Person;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What a merge would do (Phase 10A.5 and 10A.7): the blockers, the field
 * conflicts, the counts of what moves, the grants and requests it ends, and
 * the visibility side-effect per LGA. Used by the review page and,
 * re-computed under the row locks, by MergePersons.
 *
 * Counts read the link tables by person ID (including links on records
 * outside the executor's view, such as another state's incident linked
 * under a grant): they are numbers only, and those rows move too.
 */
final class MergePlan
{
    /**
     * Reasons the pair can never be merged, whoever executes it.
     *
     * @return list<string>
     */
    public static function blockers(Person $survivor, Person $source): array
    {
        $blockers = [];

        if ($survivor->id === $source->id) {
            $blockers[] = 'A record cannot be merged with itself.';
        }

        if ($survivor->trashed() || $source->trashed() || $survivor->merged_into_person_id !== null || $source->merged_into_person_id !== null) {
            $blockers[] = 'One of the records has already been merged or deleted.';
        }

        if ($survivor->state_id !== $source->state_id) {
            $blockers[] = 'The records belong to different states. Records are merged only within one state.';
        }

        if (MergeFields::conflicts($survivor, $source, 'nin')) {
            $blockers[] = 'The records have different NINs: they are different people and cannot be merged.';
        }

        return $blockers;
    }

    public static function dobConflict(Person $survivor, Person $source): bool
    {
        return MergeFields::conflicts($survivor, $source, 'date_of_birth');
    }

    public static function deceasedDiffers(Person $survivor, Person $source): bool
    {
        return $survivor->is_deceased !== $source->is_deceased;
    }

    public static function classification(Person $survivor, Person $source): Classification
    {
        return $survivor->classificationLevel()->rank() >= $source->classificationLevel()->rank()
            ? $survivor->classificationLevel()
            : $source->classificationLevel();
    }

    /**
     * What moves from the source to the survivor, as counts.
     *
     * @return array<string, int>
     */
    public static function counts(Person $source): array
    {
        $id = $source->id;
        $morph = $source->getMorphClass();

        return [
            'aliases' => DB::table('person_aliases')->where('person_id', $id)->whereNull('deleted_at')->count(),
            'photos' => DB::table('media')->where('model_type', $morph)->where('model_id', $id)->where('collection_name', Person::PHOTOS)->count(),
            'roles' => DB::table('person_roles')->where('person_id', $id)->count(),
            'associations' => DB::table('person_associations')->whereNull('deleted_at')->where(fn ($q) => $q->where('person_id', $id)->orWhere('associated_person_id', $id))->count(),
            'offices' => DB::table('political_offices')->where('person_id', $id)->whereNull('deleted_at')->count(),
            'memberships' => DB::table('criminal_group_members')->where('person_id', $id)->whereNull('deleted_at')->count(),
            'incidents' => DB::table('incident_persons')->where('person_id', $id)->whereNull('deleted_at')->distinct()->count('incident_id'),
            'poi' => DB::table('persons_of_interest')->where('person_id', $id)->whereNull('deleted_at')->count(),
            'community_links' => DB::table('communities')->where('traditional_ruler_id', $id)->count()
                + DB::table('religious_institutions')->where('leader_person_id', $id)->count()
                + DB::table('organization_contacts')->where('person_id', $id)->whereNull('deleted_at')->count(),
        ];
    }

    /**
     * Active cross-jurisdiction grants and open access requests on the
     * source, which the merge revokes and cancels.
     *
     * @return array{grants: int, requests: int}
     */
    public static function endings(Person $source): array
    {
        return [
            'grants' => self::activeGrants($source)->count(),
            'requests' => self::openRequests($source)->count(),
        ];
    }

    /** @return Builder */
    public static function activeGrants(Person $source)
    {
        return DB::table('record_access_grants')
            ->where('subject_type', $source->getMorphClass())
            ->where('subject_id', $source->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /** @return Builder */
    public static function openRequests(Person $source)
    {
        return DB::table('record_access_requests')
            ->where('subject_type', $source->getMorphClass())
            ->where('subject_id', $source->id)
            ->whereIn('status', AccessRequestStatus::openValues());
    }

    /**
     * The visibility side-effect (10A.5): when the records are owned by
     * different LGAs, the officers of the LGA that does not own the
     * survivor no longer see the person on their incidents. For each
     * possible owning LGA: the losing LGA and how many of its incidents
     * involve either record.
     *
     * @return array{survivor: array{lga: string, incidents: int}|null, source: array{lga: string, incidents: int}|null}
     */
    public static function visibility(Person $survivor, Person $source): array
    {
        if ($survivor->lga_id === $source->lga_id) {
            return ['survivor' => null, 'source' => null];
        }

        $affected = fn (int $losingLga): int => DB::table('incident_persons')
            ->whereIn('person_id', [$survivor->id, $source->id])
            ->where('lga_id', $losingLga)
            ->whereNull('deleted_at')
            ->distinct()
            ->count('incident_id');
        $name = fn (int $lga): string => (string) Lga::query()->whereKey($lga)->value('name');

        return [
            // Keep the survivor's place: the source's LGA loses sight.
            'survivor' => ['lga' => $name($source->lga_id), 'incidents' => $affected($source->lga_id)],
            // Take the source's place: the survivor's LGA loses sight.
            'source' => ['lga' => $name($survivor->lga_id), 'incidents' => $affected($survivor->lga_id)],
        ];
    }

    /**
     * Validates the executor's choices against the pair; throws on the
     * first problem.
     *
     * @throws MergeBlocked
     */
    public static function validate(Person $survivor, Person $source, MergeChoices $choices): void
    {
        foreach (self::blockers($survivor, $source) as $blocker) {
            throw new MergeBlocked($blocker);
        }

        if (self::dobConflict($survivor, $source) && ! $choices->confirmDob) {
            throw new MergeBlocked('The dates of birth differ. Confirm that these are the same person.', 'confirm_dob');
        }

        if (self::deceasedDiffers($survivor, $source) && ! $choices->confirmDeceased) {
            throw new MergeBlocked('Only one record is marked deceased. Confirm that the merged person is deceased.', 'confirm_deceased');
        }

        foreach (array_keys($choices->fields) as $field) {
            if (! array_key_exists($field, MergeFields::FIELDS)) {
                throw new MergeBlocked("Unknown field {$field}.");
            }
        }

        if (MergeFields::conflicts($survivor, $source, 'community')) {
            $chosen = $choices->field('community') === 'source' ? $source->community_id : $survivor->community_id;
            $lgaId = $choices->place === 'source' ? $source->lga_id : $survivor->lga_id;

            if ((int) DB::table('communities')->where('id', $chosen)->value('lga_id') !== $lgaId) {
                throw new MergeBlocked('The chosen community is not in the LGA that will own the record. Choose the other community or the other LGA.', 'fields.community');
            }
        }
    }

    /**
     * The community the survivor will have: the chosen (or only) one when
     * it is in the owning LGA, else the other record's when that one is,
     * else none (a community belongs to one LGA).
     */
    public static function chosenCommunity(Person $survivor, Person $source, MergeChoices $choices): ?int
    {
        $from = MergeFields::conflicts($survivor, $source, 'community')
            ? $choices->field('community')
            : MergeFields::automatic($survivor, $source, 'community');
        $lgaId = $choices->place === 'source' ? $source->lga_id : $survivor->lga_id;
        $inLga = fn (?int $community): bool => $community !== null && (int) DB::table('communities')->where('id', $community)->value('lga_id') === $lgaId;
        [$first, $second] = $from === 'source' ? [$source->community_id, $survivor->community_id] : [$survivor->community_id, $source->community_id];

        return match (true) {
            $inLga($first) => $first,
            $inLga($second) => $second,
            default => null,
        };
    }
}
