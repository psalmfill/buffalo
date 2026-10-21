<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Enums\Permission;
use App\Http\Presenters\AccessRequestPresenter;
use App\Models\RecordAccessGrant;
use App\Models\RecordAccessRequest;
use App\Models\User;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Jurisdiction\AccessRequestParties;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Jurisdiction\OversightReach;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Access grants: the oversight tab's grants list (Phase 3C, 11E export),
 * for DG and DDGs within the owning states they cover. Holders, approvers
 * and periods; never the record.
 */
final class AccessGrantsExporter extends ListExporter
{
    public function __construct(private readonly JurisdictionOptions $options) {}

    public function key(): string
    {
        return 'access_grants';
    }

    public function label(): string
    {
        return 'Access grants (oversight)';
    }

    public function page(): string
    {
        return 'access-requests.index#oversight';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', RecordAccessRequest::class) && $user->checkPermissionTo(Permission::AccessRequestsOversee->value);
    }

    public function rules(User $user): array
    {
        return AccessRequestsExporter::oversightRules($this->options, $user);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<RecordAccessGrant>
     */
    public function query(User $user, array $filters): Builder
    {
        $state = isset($filters['state']) ? $this->options->states($user)->firstWhere('ulid', $filters['state']) : null;
        $officer = isset($filters['officer']) ? AccessRequestParties::byUlid((string) $filters['officer']) : null;

        return RecordAccessGrant::query()
            ->whereHas('request', fn (Builder $q) => OversightReach::apply($user, $q)->when($state, fn (Builder $w) => $w->where('owning_state_id', $state?->id)))
            ->when($officer, fn (Builder $q) => $q->where('user_id', $officer?->id))
            ->whereBetween('created_at', AccessRequestsExporter::period($filters))
            ->latest('id');
    }

    public function with(): array
    {
        return ['request.owningState', 'request.requesterState', 'request.requesterLga'];
    }

    public function prepare(Collection $rows, User $user): void
    {
        AccessRequestParties::attach($rows->all());
    }

    public function columns(): array
    {
        return [
            ListColumn::make('holder', 'Holder', fn (RecordAccessGrant $g) => $g->user->name),
            ListColumn::make('holder_place', 'Holder\'s jurisdiction', fn (RecordAccessGrant $g) => AccessRequestPresenter::place($g->request)),
            ListColumn::make('owning_state', 'Owning state', fn (RecordAccessGrant $g) => $g->request->owningState->name),
            ListColumn::make('scope', 'Scope', fn (RecordAccessGrant $g) => $g->scope->label()),
            ListColumn::make('granted_by', 'Granted by', fn (RecordAccessGrant $g) => $g->granter->name),
            ListColumn::make('starts_at', 'Starts', fn (RecordAccessGrant $g) => self::at($g->starts_at)),
            ListColumn::make('expires_at', 'Expires', fn (RecordAccessGrant $g) => self::at($g->expires_at)),
            ListColumn::make('revoked_at', 'Revoked', fn (RecordAccessGrant $g) => self::at($g->revoked_at)),
            ListColumn::make('active', 'Active', fn (RecordAccessGrant $g) => $g->isActive()),
        ];
    }

    public function defaultColumns(): array
    {
        return ['holder', 'holder_place', 'owning_state', 'scope', 'granted_by', 'starts_at', 'expires_at', 'revoked_at', 'active'];
    }
}
