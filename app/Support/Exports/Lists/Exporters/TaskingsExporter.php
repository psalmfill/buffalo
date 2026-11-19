<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Enums\JurisdictionLevel;
use App\Enums\TaskingPriority;
use App\Enums\TaskingStatus;
use App\Enums\TaskingTargetMode;
use App\Models\Tasking;
use App\Models\TaskingRecipient;
use App\Models\User;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Lens\PlaceFilter;
use App\Support\Taskings\TaskingProgress;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Taskings (Phase 11H list and export). Never the body, a response or a
 * note. Directors and national users get each tasking's recipient counts;
 * an LGA chief gets their own LGA's status only, and never the target mode
 * (which would say that other LGAs received it).
 *
 * Boxes: `sent` (the state's sent and recalled taskings; national users:
 * every tasking in reach), `drafts` (the state's directors only) and
 * `inbox` (an LGA chief: taskings addressed to their LGA).
 */
final class TaskingsExporter extends ListExporter
{
    public function __construct(private readonly JurisdictionOptions $options) {}

    public function key(): string
    {
        return 'taskings';
    }

    public function label(): string
    {
        return 'Taskings';
    }

    public function page(): string
    {
        return 'taskings.index';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', Tasking::class);
    }

    public function rules(User $user): array
    {
        return [
            'box' => ['nullable', Rule::in(self::boxes($user))],
            'status' => ['nullable', Rule::in([TaskingStatus::Sent->value, TaskingStatus::Recalled->value])],
            'priority' => ['nullable', Rule::enum(TaskingPriority::class)],
            'target' => self::isLga($user) ? ['prohibited'] : ['nullable', Rule::enum(TaskingTargetMode::class)],
            'overdue' => ['nullable', Rule::in(['yes'])],
            'unread' => self::isLga($user) ? ['nullable', Rule::in(['yes'])] : ['prohibited'],
            'state' => ['nullable', 'string', Rule::in($this->options->states($user)->pluck('ulid'))],
            'zone' => PlaceFilter::zoneRule($user),
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * The boxes a user has: drafts only for the state's directors.
     *
     * @return list<string>
     */
    public static function boxes(User $user): array
    {
        if (self::isLga($user)) {
            return ['inbox'];
        }

        return $user->can('create', Tasking::class) ? ['sent', 'drafts'] : ['sent'];
    }

    public static function isLga(User $user): bool
    {
        return $user->jurisdiction_level === JurisdictionLevel::Lga;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Tasking>
     */
    public function query(User $user, array $filters): Builder
    {
        $box = $filters['box'] ?? self::boxes($user)[0];
        $state = isset($filters['state']) ? $this->options->states($user)->firstWhere('ulid', $filters['state']) : null;
        $zone = PlaceFilter::zone($filters['zone'] ?? null);
        $tz = (string) config('app.timezone');
        $now = CarbonImmutable::now();
        $open = fn ($q) => $q->selectRaw('1')->from('tasking_recipients')
            ->whereColumn('tasking_recipients.tasking_id', 'taskings.id')
            ->where('tasking_recipients.status', '<>', 'completed')
            // An LGA chief: their own row only.
            ->when(self::isLga($user), fn ($r) => $r->where('tasking_recipients.lga_id', $user->lga_id));

        return Tasking::query()
            ->when($box === 'drafts', fn (Builder $q) => $q->where('taskings.status', TaskingStatus::Draft->value)
                ->where('taskings.state_id', $user->state_id), fn (Builder $q) => $q->where('taskings.status', '<>', TaskingStatus::Draft->value))
            ->when($filters['status'] ?? null, fn (Builder $q, string $v) => $q->where('taskings.status', $v))
            ->when($filters['priority'] ?? null, fn (Builder $q, string $v) => $q->where('taskings.priority', $v))
            ->when($filters['target'] ?? null, fn (Builder $q, string $v) => $q->where('taskings.target_mode', $v))
            ->when(($filters['overdue'] ?? null) === 'yes', fn (Builder $q) => $q->where('taskings.status', TaskingStatus::Sent->value)
                ->whereNotNull('taskings.response_due_at')->where('taskings.response_due_at', '<=', $now)->whereExists($open))
            ->when(($filters['unread'] ?? null) === 'yes', fn (Builder $q) => $q->whereExists(fn ($r) => $r->selectRaw('1')->from('tasking_recipients')
                ->whereColumn('tasking_recipients.tasking_id', 'taskings.id')
                ->where('tasking_recipients.lga_id', $user->lga_id)
                ->where('tasking_recipients.status', 'delivered')))
            ->when($state, fn (Builder $q) => $q->where('taskings.state_id', $state?->id))
            ->tap(fn (Builder $q) => PlaceFilter::apply($q, 'taskings.state_id', $zone))
            ->when($filters['from'] ?? null, fn (Builder $q, string $v) => $q->where('taskings.sent_at', '>=', CarbonImmutable::createFromFormat('Y-m-d', $v, $tz)?->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $q, string $v) => $q->where('taskings.sent_at', '<', CarbonImmutable::createFromFormat('Y-m-d', $v, $tz)?->addDay()->startOfDay()))
            ->when($filters['q'] ?? null, fn (Builder $q, string $v) => $q->where(fn (Builder $w) => $w
                ->where('taskings.reference_no', 'ILIKE', '%'.addcslashes($v, '%_\\').'%')
                ->orWhere('taskings.title', 'ILIKE', '%'.addcslashes($v, '%_\\').'%')))
            ->orderByRaw('taskings.sent_at DESC NULLS LAST')->orderByDesc('taskings.updated_at')->orderByDesc('taskings.id');
    }

    public function classificationColumn(): string
    {
        return 'taskings.classification';
    }

    public function with(): array
    {
        return ['state'];
    }

    /**
     * Recipient counts per tasking (through the recipients' scopes: an LGA
     * chief counts only their own row) and, for LGA chiefs, their status.
     */
    public function prepare(Collection $rows, User $user): void
    {
        $ids = array_values(array_map(fn (mixed $row): int => $row instanceof Tasking ? $row->id : 0, $rows->all()));
        $progress = TaskingProgress::for($ids);
        $mine = self::isLga($user)
            ? TaskingRecipient::query()->whereIn('tasking_id', $ids)->get()->keyBy('tasking_id')
            : collect();

        foreach ($rows as $row) {
            /** @var Tasking $row */
            $p = $progress[$row->id] ?? ['total' => 0, 'read' => 0, 'acknowledged' => 0, 'completed' => 0, 'overdue' => 0];
            $row->setAttribute('export_progress', $p);
            $row->setAttribute('export_lga', self::isLga($user));
            $own = $mine->get($row->id);
            $row->setAttribute('export_own_status', $own instanceof TaskingRecipient ? $own->status->label() : null);
        }
    }

    public function columns(): array
    {
        $count = fn (string $key) => fn (Tasking $t) => $t->getAttribute('export_lga') === true ? null : (($t->getAttribute('export_progress') ?? [])[$key] ?? 0);

        return [
            ListColumn::make('reference', 'Reference', fn (Tasking $t) => $t->reference_no),
            ListColumn::make('title', 'Title', fn (Tasking $t) => $t->displayTitle()),
            ListColumn::make('priority', 'Priority', fn (Tasking $t) => $t->priority->label()),
            ListColumn::make('status', 'Status', fn (Tasking $t) => $t->status->label()),
            ListColumn::make('state', 'State', fn (Tasking $t) => $t->state->name),
            ListColumn::make('sent_at', 'Sent at', fn (Tasking $t) => self::at($t->sent_at)),
            ListColumn::make('due', 'Response due', fn (Tasking $t) => self::at($t->response_due_at)),
            ListColumn::make('target', 'Sent to', fn (Tasking $t) => $t->getAttribute('export_lga') === true ? null : $t->target_mode?->label()),
            ListColumn::make('recipients', 'LGAs', $count('total')),
            ListColumn::make('read', 'Read', $count('read')),
            ListColumn::make('acknowledged', 'Acknowledged', $count('acknowledged')),
            ListColumn::make('completed', 'Completed', $count('completed')),
            ListColumn::make('overdue', 'Overdue', $count('overdue')),
            ListColumn::make('own_status', 'Your LGA\'s status', fn (Tasking $t) => $t->getAttribute('export_own_status')),
            ListColumn::make('classification', 'Classification', fn (Tasking $t) => $t->classificationLevel()->label()),
        ];
    }

    public function defaultColumns(): array
    {
        return ['reference', 'title', 'priority', 'status', 'sent_at', 'due', 'recipients', 'read', 'acknowledged', 'completed', 'overdue', 'own_status'];
    }
}
