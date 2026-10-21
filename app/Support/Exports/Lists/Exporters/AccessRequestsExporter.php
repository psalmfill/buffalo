<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Enums\AccessRequestStatus;
use App\Enums\Permission;
use App\Http\Presenters\AccessRequestPresenter;
use App\Models\RecordAccessRequest;
use App\Models\User;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Jurisdiction\AccessRequestParties;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Jurisdiction\OversightReach;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Cross-jurisdiction access requests: the oversight tab's list (Phase 3C,
 * 11E export), for DG and DDGs within the owning states they cover. Who
 * asked whom, when and with what outcome; never the record or the free-text
 * reasons.
 */
final class AccessRequestsExporter extends ListExporter
{
    public function __construct(private readonly JurisdictionOptions $options) {}

    public function key(): string
    {
        return 'access_requests';
    }

    public function label(): string
    {
        return 'Access requests (oversight)';
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
        return self::oversightRules($this->options, $user);
    }

    /**
     * The oversight tab's filters (shared by its requests and grants lists).
     *
     * @return array<string, mixed>
     */
    public static function oversightRules(JurisdictionOptions $options, User $user): array
    {
        return [
            'state' => ['nullable', 'string', Rule::in($options->states($user)->pluck('ulid'))],
            'officer' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', Rule::enum(AccessRequestStatus::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * The period of the oversight tab: the last 30 days unless chosen.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function period(array $filters): array
    {
        return [
            isset($filters['from']) ? CarbonImmutable::parse((string) $filters['from'])->startOfDay() : CarbonImmutable::now()->subDays(30)->startOfDay(),
            isset($filters['to']) ? CarbonImmutable::parse((string) $filters['to'])->endOfDay() : CarbonImmutable::now()->endOfDay(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<RecordAccessRequest>
     */
    public function query(User $user, array $filters): Builder
    {
        $state = isset($filters['state']) ? $this->options->states($user)->firstWhere('ulid', $filters['state']) : null;
        $officer = isset($filters['officer']) ? AccessRequestParties::byUlid((string) $filters['officer']) : null;

        return OversightReach::apply($user, RecordAccessRequest::query())
            ->when($state, fn (Builder $q) => $q->where('owning_state_id', $state?->id))
            ->when($officer, fn (Builder $q) => $q->where('requester_id', $officer?->id))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->whereBetween('created_at', self::period($filters))
            ->latest('id');
    }

    public function with(): array
    {
        return ['owningState', 'requesterState', 'requesterLga', 'matchEvent', 'grant'];
    }

    public function prepare(Collection $rows, User $user): void
    {
        AccessRequestParties::attach($rows->all());
    }

    public function columns(): array
    {
        return [
            ListColumn::make('created_at', 'Requested', fn (RecordAccessRequest $r) => self::at($r->created_at)),
            ListColumn::make('requester', 'Requester', fn (RecordAccessRequest $r) => $r->requester->name),
            ListColumn::make('requester_place', 'Requester\'s jurisdiction', fn (RecordAccessRequest $r) => AccessRequestPresenter::place($r)),
            ListColumn::make('owning_state', 'Owning state', fn (RecordAccessRequest $r) => $r->owningState->name),
            ListColumn::make('match_type', 'Match', fn (RecordAccessRequest $r) => $r->matchEvent->match_type->label()),
            ListColumn::make('urgency', 'Urgency', fn (RecordAccessRequest $r) => $r->urgency->label()),
            ListColumn::make('scope', 'Scope asked', fn (RecordAccessRequest $r) => $r->scope->label()),
            ListColumn::make('requested_days', 'Days asked', fn (RecordAccessRequest $r) => $r->requested_days),
            ListColumn::make('status', 'Status', fn (RecordAccessRequest $r) => $r->status->label()),
            ListColumn::make('escalated_at', 'Escalated', fn (RecordAccessRequest $r) => self::at($r->escalated_at)),
            ListColumn::make('decided_at', 'Decided', fn (RecordAccessRequest $r) => self::at($r->decided_at)),
            ListColumn::make('granted_scope', 'Scope granted', fn (RecordAccessRequest $r) => $r->granted_scope?->label()),
            ListColumn::make('granted_days', 'Days granted', fn (RecordAccessRequest $r) => $r->granted_days),
        ];
    }

    public function defaultColumns(): array
    {
        return ['created_at', 'requester', 'requester_place', 'owning_state', 'match_type', 'urgency', 'status', 'decided_at'];
    }
}
