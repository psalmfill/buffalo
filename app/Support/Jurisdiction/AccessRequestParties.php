<?php

namespace App\Support\Jurisdiction;

use App\Models\ApprovalDelegation;
use App\Models\RecordAccessGrant;
use App\Models\RecordAccessRequest;
use App\Models\RecordMatchEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Loads the officers involved in cross-jurisdiction access (Phase 3C):
 * requesters, deciders, grant holders and granters, matching officers and
 * delegation parties.
 *
 * SANCTIONED SCOPE BYPASS (inside App\Support\Jurisdiction): users are
 * jurisdiction-scoped, but an access request by definition crosses
 * jurisdictions. The owning state's approvers must see who is asking, and
 * the requester who decided. Only these named relations are filled; which
 * requests, grants, events and delegations a user sees at all is decided by
 * the policies and the controller queries.
 */
final class AccessRequestParties
{
    /** Relation name => foreign key column, per model. */
    private const RELATIONS = [
        RecordAccessRequest::class => ['requester' => 'requester_id', 'decider' => 'decided_by'],
        RecordAccessGrant::class => ['user' => 'user_id', 'granter' => 'granted_by'],
        RecordMatchEvent::class => ['user' => 'user_id'],
        ApprovalDelegation::class => ['delegator' => 'delegator_id', 'delegate' => 'delegate_id'],
    ];

    /**
     * Fills the party relations of the given models and of their loaded
     * `grant` / `request` relations.
     *
     * @param  Model|iterable<Model>  $models
     */
    public static function attach(Model|iterable $models): void
    {
        $all = self::expand($models instanceof Model ? [$models] : $models);
        $ids = [];

        foreach ($all as $model) {
            foreach (self::RELATIONS[$model::class] ?? [] as $column) {
                $id = $model->getAttribute($column);

                if (is_int($id)) {
                    $ids[$id] = true;
                }
            }
        }

        $users = self::users(array_keys($ids));

        foreach ($all as $model) {
            foreach (self::RELATIONS[$model::class] ?? [] as $relation => $column) {
                $id = $model->getAttribute($column);
                $model->setRelation($relation, is_int($id) ? $users->get($id) : null);
            }
        }
    }

    /**
     * Officers by id (with state, LGA and role), e.g. for per-officer figures.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, User>
     */
    public static function users(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        // Deleted officers still name their past requests and decisions.
        return User::query()->withoutGlobalScope(JurisdictionScope::class)->withTrashed()
            ->with(['state', 'lga', 'roles'])
            ->whereKey($ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * "Related incident: [reference] — [type], [LGA]" for an access request
     * citing the requester's own incident (Phase 5A). The approver usually
     * cannot see that incident: this line (reference, type and LGA names)
     * is all they get, never the incident itself.
     */
    public static function incidentLine(RecordAccessRequest $request): ?string
    {
        if ($request->incident_id === null) {
            return null;
        }

        $row = DB::table('incidents as i')
            ->join('incident_types as t', 't.id', '=', 'i.incident_type_id')
            ->join('lgas as l', 'l.id', '=', 'i.lga_id')
            ->where('i.id', $request->incident_id)
            ->first(['i.reference_no', 't.name as type', 'l.name as lga']);

        if ($row === null) {
            return null;
        }

        $reference = is_string($row->reference_no) ? $row->reference_no : 'No reference yet';

        return "{$reference} — {$row->type}, {$row->lga} LGA";
    }

    /**
     * An officer by ULID, for filtering oversight lists by the officer who
     * matched or requested. The results stay limited to the overseer's reach.
     */
    public static function byUlid(string $ulid): ?User
    {
        return User::query()->withoutGlobalScope(JurisdictionScope::class)->where('ulid', $ulid)->first();
    }

    /**
     * @param  iterable<Model>  $models
     * @return list<Model>
     */
    private static function expand(iterable $models): array
    {
        $out = [];

        foreach ($models as $model) {
            $out[] = $model;

            foreach (['grant', 'request'] as $nested) {
                if ($model->relationLoaded($nested) && $model->getRelation($nested) instanceof Model) {
                    $out[] = $model->getRelation($nested);
                }
            }
        }

        return $out;
    }
}
