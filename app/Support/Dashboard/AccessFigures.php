<?php

namespace App\Support\Dashboard;

use App\Enums\AccessRequestStatus;
use App\Enums\Permission;
use App\Enums\RequestUrgency;
use App\Models\RecordAccessGrant;
use App\Models\RecordAccessRequest;
use App\Models\RecordMatchEvent;
use App\Models\User;
use App\Support\Jurisdiction\AccessRequestApprovers;
use App\Support\Jurisdiction\AccessRequestParties;
use App\Support\Jurisdiction\JurisdictionRules;
use App\Support\Jurisdiction\RestrictionType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cross-jurisdiction access figures for the dashboards (Phase 3C). Counts
 * and expiry dates only: no record details.
 */
final class AccessFigures
{
    public function __construct(
        private readonly AccessRequestApprovers $approvers,
        private readonly JurisdictionRules $rules,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function for(User $user): ?array
    {
        $figures = [];

        if ($user->checkPermissionTo(Permission::AccessRequestsCreate->value)) {
            $figures['mine'] = [
                'openRequests' => RecordAccessRequest::query()->where('requester_id', $user->id)->whereIn('status', AccessRequestStatus::openValues())->count(),
                'activeGrants' => RecordAccessGrant::query()->active()->where('user_id', $user->id)->orderBy('expires_at')->with('request.owningState')->get()
                    ->map(fn (RecordAccessGrant $g) => ['state' => $g->request->owningState->name, 'expires_at' => $g->expires_at->toIso8601String(), 'request' => $g->request->ulid])
                    ->values()->all(),
            ];
        }

        if ($user->state_id !== null && $this->approvers->isStateApprover($user, $user->state_id)) {
            $open = RecordAccessRequest::query()->where('owning_state_id', $user->state_id)->whereIn('status', AccessRequestStatus::openValues());
            $figures['owner'] = [
                'pending' => (clone $open)->count(),
                'urgent' => (clone $open)->where('urgency', RequestUrgency::Urgent->value)->count(),
                'activeGrants' => RecordAccessGrant::query()->active()->whereHas('request', fn (Builder $q) => $q->where('owning_state_id', $user->state_id))->count(),
            ];
        }

        if ($user->checkPermissionTo(Permission::AccessRequestsOversee->value)) {
            $restriction = $this->rules->restrictionFor($user, visibleToSuperAdmin: false);
            $reach = fn (Builder $q) => $restriction->type === RestrictionType::Everything ? $q : $q->whereIn('owning_state_id', $restriction->stateIds);
            $soft = (int) config('buffalo.cross_jurisdiction.soft_daily_limit', 20);
            $today = CarbonImmutable::today();

            $over = $reach(RecordMatchEvent::query())->where('suppressed', false)->where('created_at', '>=', $today)
                ->groupBy('user_id')->havingRaw('count(*) > ?', [$soft])->selectRaw('user_id, count(*) AS total')->toBase()->get();
            // Officers may be outside the viewer's user scope (a zoned DDG).
            $names = AccessRequestParties::users($over->pluck('user_id')->map(fn ($id) => (int) $id)->all())->map(fn (User $u) => $u->name);

            $figures['oversight'] = [
                'escalated' => $reach(RecordAccessRequest::query())->where('status', AccessRequestStatus::Escalated->value)->count(),
                'hitsToday' => $reach(RecordMatchEvent::query())->where('suppressed', false)->where('created_at', '>=', $today)->count(),
                'suppressedToday' => $reach(RecordMatchEvent::query())->where('suppressed', true)->where('created_at', '>=', $today)->count(),
                'overSoftLimit' => $over->map(fn (object $row) => ['name' => (string) ($names[$row->user_id] ?? '—'), 'hits' => (int) $row->total])->values()->all(),
            ];
        }

        return $figures === [] ? null : $figures;
    }
}
