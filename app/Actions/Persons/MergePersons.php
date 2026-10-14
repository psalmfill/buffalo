<?php

namespace App\Actions\Persons;

use App\Enums\AccessRequestStatus;
use App\Enums\MembershipStatus;
use App\Enums\MergeRequestStatus;
use App\Enums\Permission;
use App\Enums\RiskLevel;
use App\Enums\Role;
use App\Events\AccessGrantEnded;
use App\Models\Lga;
use App\Models\Person;
use App\Models\PersonAlias;
use App\Models\PersonMerge;
use App\Models\PersonMergeRequest;
use App\Models\PersonOfInterest;
use App\Models\RecordAccessGrant;
use App\Models\RecordAccessRequest;
use App\Models\User;
use App\Notifications\AccessGrantChanged;
use App\Notifications\PersonMergeNotification;
use App\Support\Audit\AuditLog;
use App\Support\Jurisdiction\AccessRequestParties;
use App\Support\Jurisdiction\NotificationRecipients;
use App\Support\Persons\Merge\MergeBlocked;
use App\Support\Persons\Merge\MergeChoices;
use App\Support\Persons\Merge\MergeFields;
use App\Support\Persons\Merge\MergeManifest;
use App\Support\Persons\Merge\MergePlan;
use App\Support\Persons\Merge\PersonMergeLocks;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Merges the source person into the survivor (Phase 10A.5), applying every
 * rule of 10A.4 (see docs/architecture/person-references.md) in ONE
 * transaction, under advisory and row locks on both persons, while
 * recording a manifest that UndoPersonMerge reverses.
 *
 * Authorisation (PersonPolicy::merge on both, checked again here): the
 * executor holds persons.merge, and both records are in their OWN
 * jurisdiction and within their clearance. Step-up is enforced by the
 * controller. Link rows are moved by person ID through the query builder,
 * including rows on records outside the executor's view (another state's
 * incident linked under a grant): they belong to the person, and leaving
 * them on a deleted record would break them.
 */
final class MergePersons
{
    public const REASON = 'record merged';

    public function __construct(private readonly NotificationRecipients $recipients) {}

    /**
     * @throws MergeBlocked
     * @throws AuthorizationException
     */
    public function __invoke(Person $survivor, Person $source, MergeChoices $choices, User $actor, ?PersonMergeRequest $request = null): PersonMerge
    {
        $this->authorise($survivor, $source, $actor);

        $ulid = strtolower((string) Str::ulid());
        $revoked = new Collection;
        $cancelledRequests = [];
        $cancelledMergeRequests = [];
        $places = [];

        $merge = DB::transaction(function () use ($survivor, $source, $choices, $actor, $request, $ulid, &$revoked, &$cancelledRequests, &$cancelledMergeRequests, &$places): PersonMerge {
            [$survivor, $source] = PersonMergeLocks::lock($survivor, $source, $ulid);
            // Re-checked under the locks: a concurrent merge or edit may have changed either.
            $this->authorise($survivor, $source, $actor);
            MergePlan::validate($survivor, $source, $choices);

            $places = array_values(array_unique([$survivor->lga_id, $source->lga_id]));
            $manifest = new MergeManifest;
            $before = MergeFields::snapshot($survivor);
            $names = [$survivor->name, $source->name];

            $fieldChoices = $this->applyFields($survivor, $source, $choices, $actor);
            $this->mergeAliases($survivor, $source, $names, $actor, $manifest);
            $this->mergeRoles($survivor, $source, $manifest);
            $this->mergeAssociations($survivor, $source, $manifest);
            $poiChoice = $this->mergePoi($survivor, $source, $choices, $manifest, $ulid, $actor);
            $this->repoint('political_offices', 'person_id', $source, $survivor, $manifest);
            $this->mergeMemberships($survivor, $source, $manifest);
            $this->mergeIncidentPersons($survivor, $source, $manifest);
            $this->repoint('communities', 'traditional_ruler_id', $source, $survivor, $manifest);
            $this->repoint('religious_institutions', 'leader_person_id', $source, $survivor, $manifest);
            $this->repoint('organization_contacts', 'person_id', $source, $survivor, $manifest);
            $photoChoice = $this->mergePhotos($survivor, $source, $choices, $manifest);
            $revoked = $this->revokeGrants($source, $actor, $manifest);
            $cancelledRequests = $this->cancelAccessRequests($source, $actor, $manifest);
            $cancelledMergeRequests = $this->settleMergeRequests($survivor, $source, $request, $actor, $manifest);

            // The source: soft-deleted and marked as merged (its own fields
            // are never changed, so an undo only has to restore it).
            $manifest->update('persons', $source->id, [
                'deleted_at' => now(),
                'merged_into_person_id' => $survivor->id,
                'merged_at' => now(),
                'merged_by' => $actor->id,
            ], 'merged');

            $survivor->refresh();
            $merge = new PersonMerge;
            $merge->forceFill([
                'ulid' => $ulid,
                'state_id' => $survivor->state_id,
                'survivor_person_id' => $survivor->id,
                'source_person_id' => $source->id,
                'request_id' => $request?->id,
                'field_choices' => [...$fieldChoices, ...$poiChoice, ...$photoChoice],
                'manifest' => ['version' => 1, 'entries' => $manifest->entries()],
                // The survivor's fields before (and right after) the merge,
                // encrypted: the activity log never holds encrypted values.
                'survivor_prior_values' => Crypt::encrypt(['before' => $before, 'after' => MergeFields::snapshot($survivor)]),
                'executed_by' => $actor->id,
                'executed_at' => now(),
            ])->save();

            AuditLog::event('person_merged_into', 'Merged into another person record', $source, ['merge' => $ulid, 'survivor' => $survivor->ulid], $actor);
            AuditLog::event('person_merged_from', 'Another person record was merged into this one', $survivor, ['merge' => $ulid, 'source' => $source->ulid], $actor);

            return $merge;
        });

        $this->notify($merge, $actor, $places, $revoked, $cancelledRequests, $cancelledMergeRequests);

        return $merge;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorise(Person $survivor, Person $source, User $actor): void
    {
        if (! $actor->checkPermissionTo(Permission::PersonsMerge->value) || ! $actor->can('merge', $survivor) || ! $actor->can('merge', $source)) {
            throw new AuthorizationException('You may not merge these records.');
        }
    }

    /**
     * Scalar fields, place, classification and deceased flag, written
     * through the model (ciphertext and blind indexes regenerated).
     *
     * @return array<string, string>
     */
    private function applyFields(Person $survivor, Person $source, MergeChoices $choices, User $actor): array
    {
        $recorded = [];
        $values = [];

        foreach (array_keys(MergeFields::FIELDS) as $field) {
            if ($field === 'community') {
                continue;
            }

            $from = MergeFields::automatic($survivor, $source, $field);

            if (MergeFields::conflicts($survivor, $source, $field)) {
                $from = $choices->field($field);
                $recorded[$field] = $from;
            }

            $values[$field] = ($from === 'source' ? $source : $survivor)->getAttribute($field);
        }

        if (MergeFields::conflicts($survivor, $source, 'community')) {
            $recorded['community'] = $choices->field('community');
        }

        $place = $choices->place === 'source' ? $source : $survivor;

        if ($survivor->lga_id !== $source->lga_id) {
            $recorded['place'] = $choices->place;
        }

        $survivor->fill($values);
        $survivor->forceFill([
            'state_id' => $place->state_id,
            'lga_id' => $place->lga_id,
            'community_id' => MergePlan::chosenCommunity($survivor, $source, $choices),
            'classification' => MergePlan::classification($survivor, $source)->value,
            'is_deceased' => $survivor->is_deceased || $source->is_deceased,
            'updated_by' => $actor->id,
        ])->save();

        return $recorded;
    }

    /**
     * Union of aliases, deduplicated by the normalised alias; names that
     * did not become the survivor's name are added as aliases.
     *
     * @param  list<string>  $names  both full names before the merge
     */
    private function mergeAliases(Person $survivor, Person $source, array $names, User $actor, MergeManifest $manifest): void
    {
        $survivor->refresh();
        $taken = DB::table('person_aliases')->where('person_id', $survivor->id)->whereNull('deleted_at')->pluck('search_alias')->all();
        $taken[] = $survivor->search_name;

        foreach (DB::table('person_aliases')->where('person_id', $source->id)->whereNull('deleted_at')->orderBy('id')->get(['id', 'search_alias']) as $alias) {
            if (in_array($alias->search_alias, $taken, true)) {
                continue; // a duplicate: stays with the source
            }

            $manifest->update('person_aliases', (int) $alias->id, ['person_id' => $survivor->id]);
            $taken[] = $alias->search_alias;
        }

        foreach ($names as $name) {
            $normalised = (string) DB::selectOne('SELECT buffalo_search_text(?) AS t', [$name])->t;

            if ($normalised === '' || in_array($normalised, $taken, true)) {
                continue;
            }

            $alias = new PersonAlias(['alias' => $name]);
            $alias->forceFill(['person_id' => $survivor->id, 'created_by' => $actor->id])->save();
            $manifest->created('person_aliases', $alias->id);
            $taken[] = $normalised;
        }
    }

    /** Union of roles: a role on both stays the survivor's, active if active on either. */
    private function mergeRoles(Person $survivor, Person $source, MergeManifest $manifest): void
    {
        $existing = DB::table('person_roles')->where('person_id', $survivor->id)->get(['id', 'person_role_type_id', 'is_active'])->keyBy('person_role_type_id');

        foreach (DB::table('person_roles')->where('person_id', $source->id)->orderBy('id')->get(['id', 'person_role_type_id', 'is_active']) as $role) {
            $own = $existing->get($role->person_role_type_id);

            if ($own === null) {
                $manifest->update('person_roles', (int) $role->id, ['person_id' => $survivor->id]);
            } elseif ($role->is_active && ! $own->is_active) {
                $manifest->update('person_roles', (int) $own->id, ['is_active' => true]);
            }
        }
    }

    /**
     * Associations repointed; the link between the two records dropped; a
     * pair the survivor already has kept once, with the notes combined.
     */
    private function mergeAssociations(Person $survivor, Person $source, MergeManifest $manifest): void
    {
        $links = DB::table('person_associations')->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('person_id', $source->id)->orWhere('associated_person_id', $source->id))
            ->orderBy('id')->get(['id', 'person_id', 'associated_person_id', 'notes']);

        foreach ($links as $link) {
            $other = (int) ($link->person_id === $source->id ? $link->associated_person_id : $link->person_id);

            if ($other === $survivor->id) {
                $manifest->drop('person_associations', (int) $link->id);

                continue;
            }

            $existing = DB::table('person_associations')->whereNull('deleted_at')
                ->where(fn ($q) => $q->where(fn ($p) => $p->where('person_id', $survivor->id)->where('associated_person_id', $other))
                    ->orWhere(fn ($p) => $p->where('person_id', $other)->where('associated_person_id', $survivor->id)))
                ->first(['id', 'notes']);

            if ($existing !== null) {
                $manifest->drop('person_associations', (int) $link->id);
                $notes = self::combineText($existing->notes, $link->notes, $source);

                if ($notes !== $existing->notes) {
                    $manifest->update('person_associations', (int) $existing->id, ['notes' => $notes]);
                }

                continue;
            }

            $column = $link->person_id === $source->id ? 'person_id' : 'associated_person_id';
            $manifest->update('person_associations', (int) $link->id, [$column => $survivor->id]);
        }
    }

    /**
     * POI: moved when only the source has one; combined into the
     * survivor's when both do.
     *
     * @return array<string, string>
     */
    private function mergePoi(Person $survivor, Person $source, MergeChoices $choices, MergeManifest $manifest, string $ulid, User $actor): array
    {
        $columns = ['id', 'ulid', 'risk_level', 'status', 'offence_categories', 'known_offences', 'modus_operandi', 'last_known_location', 'last_known_location_description', 'last_sighted_at', 'review_due_at'];
        $theirs = DB::table('persons_of_interest')->where('person_id', $source->id)->whereNull('deleted_at')->first($columns);

        if ($theirs === null) {
            return [];
        }

        $survivor->refresh();
        $ours = DB::table('persons_of_interest')->where('person_id', $survivor->id)->whereNull('deleted_at')->first($columns);

        if ($ours === null) {
            $manifest->update('persons_of_interest', (int) $theirs->id, [
                'person_id' => $survivor->id,
                'state_id' => $survivor->state_id,
                'lga_id' => $survivor->lga_id,
            ]);
            $this->poiEvent((int) $theirs->id, 'poi_merged', 'Moved to the surviving person record in a merge', ['merge' => $ulid], $actor);

            return [];
        }

        $risk = RiskLevel::from((string) $ours->risk_level)->rank() >= RiskLevel::from((string) $theirs->risk_level)->rank() ? $ours->risk_level : $theirs->risk_level;
        /** @var list<string> $categories */
        $categories = array_values(array_unique([...(json_decode((string) ($ours->offence_categories ?? '[]'), true) ?: []), ...(json_decode((string) ($theirs->offence_categories ?? '[]'), true) ?: [])]));
        sort($categories);
        $theirsLater = $theirs->last_sighted_at !== null && ($ours->last_sighted_at === null || strtotime((string) $theirs->last_sighted_at) > strtotime((string) $ours->last_sighted_at));
        $reviewDue = array_filter([$ours->review_due_at, $theirs->review_due_at]);

        $manifest->update('persons_of_interest', (int) $ours->id, [
            'risk_level' => $risk,
            'status' => $choices->poiStatus === 'source' ? $theirs->status : $ours->status,
            'offence_categories' => json_encode($categories),
            'known_offences' => self::combineText($ours->known_offences, $theirs->known_offences, $source),
            'modus_operandi' => self::combineText($ours->modus_operandi, $theirs->modus_operandi, $source),
            'last_known_location' => $theirsLater || $ours->last_known_location === null ? ($theirs->last_known_location ?? $ours->last_known_location) : $ours->last_known_location,
            'last_known_location_description' => self::combineText($ours->last_known_location_description, $theirs->last_known_location_description, $source),
            'last_sighted_at' => $theirsLater ? $theirs->last_sighted_at : $ours->last_sighted_at,
            'review_due_at' => $reviewDue === [] ? null : min($reviewDue),
        ]);
        $manifest->drop('persons_of_interest', (int) $theirs->id, ['merged_into_poi_id' => $ours->id]);

        $this->poiEvent((int) $ours->id, 'poi_combined', 'Combined with the POI record of a merged duplicate', ['merge' => $ulid, 'from' => $theirs->ulid], $actor);
        $this->poiEvent((int) $theirs->id, 'poi_merged_into', 'Combined into the POI record of the surviving person', ['merge' => $ulid, 'into' => $ours->ulid], $actor);

        return ['poi_status' => $choices->poiStatus];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function poiEvent(int $id, string $event, string $description, array $properties, User $actor): void
    {
        $poi = PersonOfInterest::withTrashed()->whereKey($id)->first();

        if ($poi instanceof PersonOfInterest) {
            AuditLog::event($event, $description, $poi, $properties, $actor);
        }
    }

    /** Group memberships repointed; the same group on both kept once, with the stronger status. */
    private function mergeMemberships(Person $survivor, Person $source, MergeManifest $manifest): void
    {
        $columns = ['id', 'criminal_group_id', 'membership_status', 'since', 'notes'];
        $ours = DB::table('criminal_group_members')->where('person_id', $survivor->id)->whereNull('deleted_at')->get($columns)->keyBy('criminal_group_id');
        $strength = fn (string $status): int => match (MembershipStatus::from($status)) {
            MembershipStatus::Confirmed => 3,
            MembershipStatus::Suspected => 2,
            MembershipStatus::Former => 1,
        };

        foreach (DB::table('criminal_group_members')->where('person_id', $source->id)->whereNull('deleted_at')->orderBy('id')->get($columns) as $theirs) {
            $own = $ours->get($theirs->criminal_group_id);

            if ($own === null) {
                $manifest->update('criminal_group_members', (int) $theirs->id, ['person_id' => $survivor->id]);

                continue;
            }

            $manifest->drop('criminal_group_members', (int) $theirs->id);
            $since = array_filter([$own->since, $theirs->since]);
            $after = [
                'membership_status' => $strength((string) $theirs->membership_status) > $strength((string) $own->membership_status) ? $theirs->membership_status : $own->membership_status,
                'since' => $since === [] ? null : min($since),
                'notes' => self::combineText($own->notes, $theirs->notes, $source),
            ];

            if ($after !== ['membership_status' => $own->membership_status, 'since' => $own->since, 'notes' => $own->notes]) {
                $manifest->update('criminal_group_members', (int) $own->id, $after);
            }
        }
    }

    /**
     * Incident persons repointed (locked incidents through the merge
     * exception); the same incident with the same involvement kept once.
     */
    private function mergeIncidentPersons(Person $survivor, Person $source, MergeManifest $manifest): void
    {
        $columns = ['id', 'incident_id', 'involvement', 'notes'];
        $ours = DB::table('incident_persons')->where('person_id', $survivor->id)->whereNull('deleted_at')->get($columns)
            ->keyBy(fn ($row) => $row->incident_id.'|'.$row->involvement);

        foreach (DB::table('incident_persons')->where('person_id', $source->id)->whereNull('deleted_at')->orderBy('id')->get($columns) as $theirs) {
            $own = $ours->get($theirs->incident_id.'|'.$theirs->involvement);

            if ($own === null) {
                $manifest->update('incident_persons', (int) $theirs->id, ['person_id' => $survivor->id]);

                continue;
            }

            $manifest->drop('incident_persons', (int) $theirs->id);
            $notes = self::combineText($own->notes, $theirs->notes, $source);

            if ($notes !== $own->notes) {
                $manifest->update('incident_persons', (int) $own->id, ['notes' => $notes]);
            }
        }
    }

    /** Repoints the source's live rows of a simple link. */
    private function repoint(string $table, string $column, Person $source, Person $survivor, MergeManifest $manifest): void
    {
        // Live rows only: soft-deleted links stay with the source as history.
        $query = DB::table($table)->where($column, $source->id)->whereNull('deleted_at');

        foreach ($query->orderBy('id')->pluck('id') as $id) {
            $manifest->update($table, (int) $id, [$column => $survivor->id]);
        }
    }

    /**
     * Every photo moves; the chosen current photo is renumbered last.
     *
     * @return array<string, string>
     */
    private function mergePhotos(Person $survivor, Person $source, MergeChoices $choices, MergeManifest $manifest): array
    {
        $morph = $survivor->getMorphClass();
        $current = fn (Person $person) => DB::table('media')->where('model_type', $morph)->where('model_id', $person->id)->where('collection_name', Person::PHOTOS)
            ->orderByDesc('order_column')->orderByDesc('id')->value('id');
        $ours = $current($survivor);
        $theirs = $current($source);

        if ($theirs === null) {
            return [];
        }

        foreach (DB::table('media')->where('model_type', $morph)->where('model_id', $source->id)->orderBy('id')->pluck('id') as $id) {
            $manifest->update('media', (int) $id, ['model_id' => $survivor->id]);
        }

        $chosen = $choices->photo === 'source' || $ours === null ? $theirs : $ours;
        $top = (int) DB::table('media')->where('model_type', $morph)->where('model_id', $survivor->id)->where('collection_name', Person::PHOTOS)->max('order_column');
        $manifest->update('media', (int) $chosen, ['order_column' => $top + 1]);

        return $ours === null ? [] : ['photo' => $choices->photo === 'source' ? 'source' : 'survivor'];
    }

    /**
     * Active grants on the source: revoked ("record merged"), never transferred.
     *
     * @return Collection<int, RecordAccessGrant>
     */
    private function revokeGrants(Person $source, User $actor, MergeManifest $manifest): Collection
    {
        $ids = MergePlan::activeGrants($source)->lockForUpdate()->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            $manifest->final('record_access_grants', (int) $id, 'revoked', [
                'revoked_at' => now(),
                'revoked_by' => $actor->id,
                'revocation_reason' => self::REASON,
            ]);
        }

        $grants = RecordAccessGrant::query()->with(['user', 'request'])->whereKey($ids->all())->get();

        foreach ($grants as $grant) {
            AuditLog::event('access_grant_revoked', 'Temporary access revoked: the record was merged', $grant->request, ['reason' => self::REASON, 'grant' => $grant->ulid], $actor);
        }

        return $grants;
    }

    /**
     * Open access requests on the source: cancelled ("record merged").
     *
     * @return list<array{requester: int, ulid: string}>
     */
    private function cancelAccessRequests(Person $source, User $actor, MergeManifest $manifest): array
    {
        $rows = MergePlan::openRequests($source)->lockForUpdate()->orderBy('id')->get(['id', 'ulid', 'requester_id']);

        foreach ($rows as $row) {
            $manifest->final('record_access_requests', (int) $row->id, 'cancelled', [
                'status' => AccessRequestStatus::Cancelled->value,
                'decision_note' => self::REASON,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ]);
            AuditLog::event('access_request_cancelled', 'Access request cancelled: the record was merged', RecordAccessRequest::query()->whereKey($row->id)->first(), ['reason' => self::REASON], $actor);
        }

        return array_values($rows->map(fn ($row) => ['requester' => (int) $row->requester_id, 'ulid' => (string) $row->ulid])->all());
    }

    /**
     * The executed request is marked executed; any other pending request
     * involving the source is cancelled.
     *
     * @return list<int> requesters of cancelled merge requests
     */
    private function settleMergeRequests(Person $survivor, Person $source, ?PersonMergeRequest $request, User $actor, MergeManifest $manifest): array
    {
        $decided = ['decided_by' => $actor->id, 'decided_at' => now()];

        if ($request !== null) {
            if ($request->status !== MergeRequestStatus::Pending || ! $request->involves($survivor->id) || ! $request->involves($source->id)) {
                throw new MergeBlocked('The merge request is no longer pending, or is for other records.');
            }

            $manifest->final('person_merge_requests', $request->id, 'executed', ['status' => MergeRequestStatus::Executed->value, ...$decided]);
        }

        $others = DB::table('person_merge_requests')->where('status', MergeRequestStatus::Pending->value)
            ->where(fn ($q) => $q->where('person_a_id', $source->id)->orWhere('person_b_id', $source->id))
            ->when($request !== null, fn ($q) => $q->where('id', '<>', $request?->id))
            ->orderBy('id')->get(['id', 'requested_by']);

        foreach ($others as $other) {
            $manifest->final('person_merge_requests', (int) $other->id, 'cancelled', ['status' => MergeRequestStatus::Cancelled->value, 'decision_note' => self::REASON, ...$decided]);
        }

        return array_values($others->pluck('requested_by')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * After the commit. Payloads carry the survivor's link only.
     *
     * @param  list<int>  $lgaIds  both records' LGAs before the merge
     * @param  Collection<int, RecordAccessGrant>  $revoked
     * @param  list<array{requester: int, ulid: string}>  $cancelledRequests
     * @param  list<int>  $cancelledMergeRequests
     */
    private function notify(PersonMerge $merge, User $actor, array $lgaIds, Collection $revoked, array $cancelledRequests, array $cancelledMergeRequests): void
    {
        $survivor = Person::query()->whereKey($merge->survivor_person_id)->value('ulid');
        $path = '/persons/'.$survivor;
        $officers = new Collection;

        foreach (Lga::query()->whereKey($lgaIds)->get() as $lga) {
            $officers = $officers->merge($this->recipients->resolve(Role::LgaSecurityChief, $lga));
            $officers = $officers->merge($this->recipients->resolve(Role::StateDirector, $lga));
        }

        foreach ($officers->unique('id')->reject(fn (User $user) => $user->id === $actor->id) as $user) {
            $user->notify(new PersonMergeNotification(PersonMergeNotification::MERGED, $path));
        }

        foreach ($revoked as $grant) {
            AccessRequestParties::attach($grant);
            $grant->user->notify(new AccessGrantChanged($grant->request->ulid, 'revoked', $grant->expires_at->toDayDateTimeString()));
            AccessGrantEnded::dispatch($grant, $grant->user->ulid);
        }

        foreach ($this->recipients->users(array_column($cancelledRequests, 'requester')) as $user) {
            foreach ($cancelledRequests as $cancelled) {
                if ($cancelled['requester'] === $user->id) {
                    $user->notify(new PersonMergeNotification(PersonMergeNotification::ACCESS_REQUEST_CANCELLED, '/access-requests/'.$cancelled['ulid']));
                }
            }
        }

        foreach ($this->recipients->users($cancelledMergeRequests) as $user) {
            $user->notify(new PersonMergeNotification(PersonMergeNotification::MERGE_REQUEST_CANCELLED, '/persons/merge-requests'));
        }
    }

    /**
     * Two texts combined, the source's labelled by its reference.
     */
    public static function combineText(?string $ours, ?string $theirs, Person $source): ?string
    {
        $theirs = $theirs === null ? '' : trim($theirs);
        $ours = $ours === null ? '' : trim($ours);

        if ($theirs === '' || $theirs === $ours) {
            return $ours === '' ? null : $ours;
        }

        $labelled = "[From merged record {$source->ulid}] {$theirs}";

        return $ours === '' ? $labelled : "{$ours}\n\n{$labelled}";
    }
}
