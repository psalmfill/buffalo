<?php

namespace App\Models;

use App\Enums\Classification;
use App\Enums\TaskingPriority;
use App\Enums\TaskingStatus;
use App\Enums\TaskingTargetMode;
use App\Models\Concerns\HasClassification;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasRichText;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Models\Concerns\LogsReads;
use App\Support\Classification\Contracts\Classified;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use App\Support\Jurisdiction\Contracts\LgaVisibilityRule;
use Carbon\CarbonImmutable;
use Database\Factories\TaskingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * A tasking (Phase 11H): a written instruction from a State Director or
 * Deputy to the LGA Security Chiefs of chosen LGAs of their state.
 *
 * Jurisdiction: the sender's state (state-level record). State-level and
 * national users see the state's taskings through the usual state rule. An
 * LGA chief sees a tasking only once it is sent and only when their LGA is a
 * recipient (LgaVisibilityRule), and then sees only their own LGA's row
 * (TaskingRecipient is scoped by its own LGA). Classification applies on
 * top. Never grantable and never disseminable.
 *
 * Once sent, the content and recipients are fixed (database trigger);
 * corrections are a recall with a reason, or a follow-up note.
 *
 * @property int $id
 * @property string $ulid
 * @property string|null $reference_no
 * @property int $state_id
 * @property string|null $title
 * @property string|null $body
 * @property string|null $body_text
 * @property TaskingPriority $priority
 * @property CarbonImmutable|null $response_due_at
 * @property TaskingTargetMode|null $target_mode
 * @property list<int>|null $draft_lga_ids
 * @property Classification $classification
 * @property TaskingStatus $status
 * @property int|null $sent_by
 * @property CarbonImmutable|null $sent_at
 * @property int|null $recalled_by
 * @property CarbonImmutable|null $recalled_at
 * @property string|null $recall_reason
 * @property CarbonImmutable|null $overdue_summary_sent_on
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read State $state
 * @property-read User|null $sender
 * @property-read User|null $recaller
 * @property-read User|null $creator
 */
class Tasking extends Model implements Classified, JurisdictionScoped, LgaVisibilityRule
{
    use HasClassification;

    /** @use HasFactory<TaskingFactory> */
    use HasFactory;

    use HasJurisdiction;
    use HasRichText;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use LogsReads;
    use SoftDeletes;

    /** State-level records: no LGA column (LGA chiefs: applyLgaVisibility). */
    protected ?string $jurisdictionLgaColumn = null;

    protected $fillable = ['title', 'body', 'priority', 'response_due_at'];

    protected $hidden = ['id', 'state_id', 'sent_by', 'recalled_by', 'created_by', 'updated_by', 'draft_lga_ids'];

    protected $attributes = [
        'classification' => 'restricted',
        'status' => 'draft',
        'priority' => 'routine',
    ];

    /**
     * @return list<string>
     */
    public function richTextFields(): array
    {
        return ['body'];
    }

    protected function casts(): array
    {
        return [
            'priority' => TaskingPriority::class,
            'status' => TaskingStatus::class,
            'target_mode' => TaskingTargetMode::class,
            'classification' => Classification::class,
            'draft_lga_ids' => 'array',
            'response_due_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'recalled_at' => 'immutable_datetime',
            'overdue_summary_sent_on' => 'immutable_date',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function auditExcept(): array
    {
        return ['draft_lga_ids', 'overdue_summary_sent_on'];
    }

    /** An LGA chief: sent (or recalled) taskings addressed to their LGA. */
    public function applyLgaVisibility(Builder $query, int $lgaId): void
    {
        $query->where($this->qualifyColumn('status'), '<>', TaskingStatus::Draft->value)
            ->whereExists(fn ($sub) => $sub->selectRaw('1')->from('tasking_recipients')
                ->whereColumn('tasking_recipients.tasking_id', $this->qualifyColumn('id'))
                ->where('tasking_recipients.lga_id', $lgaId));
    }

    public function visibleToLga(int $lgaId): bool
    {
        return $this->status !== TaskingStatus::Draft
            // The membership check itself, not a read of the row.
            && DB::table('tasking_recipients')->where('tasking_id', $this->id)->where('lga_id', $lgaId)->exists();
    }

    public function isDraft(): bool
    {
        return $this->status === TaskingStatus::Draft;
    }

    public function isSent(): bool
    {
        return $this->status === TaskingStatus::Sent;
    }

    /** The title, or "Tasking from the State Director, [date]". */
    public function displayTitle(): string
    {
        if ($this->title !== null && trim($this->title) !== '') {
            return $this->title;
        }

        $date = ($this->sent_at ?? $this->created_at ?? CarbonImmutable::now())
            ->setTimezone((string) config('app.timezone'))->format('j M Y');

        return "Tasking from the State Director, {$date}";
    }

    /** Due and not yet passed? Null when no due date. */
    public function isPastDue(?CarbonImmutable $now = null): bool
    {
        return $this->response_due_at !== null && $this->response_due_at->lessThanOrEqualTo($now ?? CarbonImmutable::now());
    }

    /**
     * @param  Builder<Tasking>  $query
     * @return Builder<Tasking>
     */
    public function scopeDelivered(Builder $query): Builder
    {
        return $query->where('taskings.status', '<>', TaskingStatus::Draft->value);
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /**
     * Scoped: an LGA chief loads only their own LGA's row.
     *
     * @return HasMany<TaskingRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(TaskingRecipient::class);
    }

    /**
     * @return HasMany<TaskingNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(TaskingNote::class);
    }

    /**
     * @return HasMany<TaskingAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TaskingAttachment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recaller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recalled_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
