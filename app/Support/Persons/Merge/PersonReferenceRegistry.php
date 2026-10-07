<?php

namespace App\Support\Persons\Merge;

/**
 * Every reference to `persons.id` in the schema, and the merge rule that
 * applies to it (Phase 10A.1; documented in
 * docs/architecture/person-references.md).
 *
 * PersonReferenceRegistryTest compares this list with the live schema: a
 * new foreign key to `persons`, or a new polymorphic (`*_type`/`*_id`)
 * column pair, fails the suite until it is registered here with a rule
 * (and MergePersons and UndoPersonMerge handle that rule).
 */
final class PersonReferenceRegistry
{
    /** Repointed to the survivor. */
    public const REPOINT = 'repoint';

    /** Union; a role on both: one row, active if active on either. */
    public const ROLES = 'roles';

    /** Union, deduplicated by the normalised alias; losing names added. */
    public const ALIASES = 'aliases';

    /** Repointed; a link between the two dropped; duplicate pairs combined. */
    public const ASSOCIATIONS = 'associations';

    /** Moved, or combined with the survivor's POI row. */
    public const POI = 'poi';

    /** Repointed; the same group on both: one row with the stronger status. */
    public const GROUP_MEMBERSHIPS = 'group_memberships';

    /** Repointed (locked incidents through the merge exception); same incident and involvement combined. */
    public const INCIDENT_PERSONS = 'incident_persons';

    /** All photos moved to the survivor; the executor picks the current one. */
    public const PHOTOS = 'photos';

    /** Regenerated for the survivor through the model; the source's stay with the source. */
    public const BLIND_INDEXES = 'blind_indexes';

    /** Active grants on the source revoked ("record merged"); never transferred. */
    public const REVOKE_GRANTS = 'revoke_grants';

    /** Open access requests on the source cancelled ("record merged"); never transferred. */
    public const CANCEL_REQUESTS = 'cancel_requests';

    /** History: unchanged, stays attached to the source's ID. */
    public const HISTORY = 'history';

    /** Merge bookkeeping (Phase 10A itself). */
    public const BOOKKEEPING = 'bookkeeping';

    /** Pending merge requests involving the source are cancelled ("record merged"). */
    public const MERGE_REQUESTS = 'merge_requests';

    /**
     * Foreign keys to persons(id): "table.column" => rule.
     *
     * @var array<string, string>
     */
    public const FOREIGN_KEYS = [
        'communities.traditional_ruler_id' => self::REPOINT,
        'criminal_group_members.person_id' => self::GROUP_MEMBERSHIPS,
        'incident_persons.person_id' => self::INCIDENT_PERSONS,
        'organization_contacts.person_id' => self::REPOINT,
        'person_aliases.person_id' => self::ALIASES,
        'person_associations.associated_person_id' => self::ASSOCIATIONS,
        'person_associations.person_id' => self::ASSOCIATIONS,
        'person_duplicate_dismissals.person_a_id' => self::BOOKKEEPING,
        'person_duplicate_dismissals.person_b_id' => self::BOOKKEEPING,
        'person_merge_requests.person_a_id' => self::MERGE_REQUESTS,
        'person_merge_requests.person_b_id' => self::MERGE_REQUESTS,
        'person_merge_requests.suggested_survivor_id' => self::MERGE_REQUESTS,
        'person_merges.source_person_id' => self::BOOKKEEPING,
        'person_merges.survivor_person_id' => self::BOOKKEEPING,
        'person_roles.person_id' => self::ROLES,
        'persons.merged_into_person_id' => self::BOOKKEEPING,
        'persons_of_interest.person_id' => self::POI,
        'political_offices.person_id' => self::REPOINT,
        'religious_institutions.leader_person_id' => self::REPOINT,
    ];

    /**
     * Polymorphic columns that can hold App\Models\Person: "table.type
     * column" => rule.
     *
     * @var array<string, string>
     */
    public const POLYMORPHIC = [
        'activity_log.subject_type' => self::HISTORY,
        'blind_indexes.indexable_type' => self::BLIND_INDEXES,
        'media.model_type' => self::PHOTOS,
        'record_access_grants.subject_type' => self::REVOKE_GRANTS,
        'record_access_logs.subject_type' => self::HISTORY,
        'record_access_requests.subject_type' => self::CANCEL_REQUESTS,
        'record_match_events.subject_type' => self::HISTORY,
    ];

    /**
     * Polymorphic columns that never hold a person, with the reason.
     *
     * @var array<string, string>
     */
    public const NOT_PERSONS = [
        'activity_log.causer_type' => 'the user who acted',
        'boundary_simplified.place_type' => 'states and LGAs',
        'dissemination_gap_alerts.subject_type' => 'threat reports and flashpoints',
        'dissemination_requests.subject_type' => 'threat reports and flashpoints',
        'model_has_permissions.model_type' => 'users',
        'model_has_roles.model_type' => 'users',
        'notifications.notifiable_type' => 'users',
        'record_disseminations.subject_type' => 'threat reports and flashpoints',
    ];
}
