<?php

namespace App\Models;

use App\Enums\TaskingRecipientStatus;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasRichText;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One recipient LGA of a tasking (Phase 11H), created on send. Recipients
 * are LGAs, not people: whoever is the LGA's Security Chief sees it, so a
 * successor inherits their predecessor's taskings.
 *
 * Scoped by its OWN state_id/lga_id (access rule 9): an LGA chief reaches
 * only their LGA's row, never which other LGAs received the tasking or
 * what they answered. Also visible only while its tasking is (which adds
 * the tasking's classification).
 *
 * @property int $id
 * @property string $ulid
 * @property int $tasking_id
 * @property int $state_id
 * @property int $lga_id
 * @property TaskingRecipientStatus $status
 * @property CarbonImmutable|null $read_at
 * @property int|null $read_by
 * @property CarbonImmutable|null $acknowledged_at
 * @property int|null $acknowledged_by
 * @property CarbonImmutable|null $completed_at
 * @property int|null $completed_by
 * @property string|null $response the completion report
 * @property string|null $response_text
 * @property CarbonImmutable|null $overdue_notified_at
 * @property CarbonImmutable|null $created_at
 * @property-read Tasking|null $tasking
 * @property-read Lga $lga
 * @property-read User|null $reader
 * @property-read User|null $acknowledger
 * @property-read User|null $completer
 */
class TaskingRecipient extends Model implements JurisdictionScoped
{
    use HasJurisdiction;
    use HasRichText;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;

    protected $fillable = [];

    protected $hidden = ['id', 'tasking_id', 'state_id', 'lga_id', 'read_by', 'acknowledged_by', 'completed_by'];

    protected $attributes = ['status' => 'delivered'];

    /**
     * @return list<string>
     */
    public function richTextFields(): array
    {
        return ['response'];
    }

    protected function casts(): array
    {
        return [
            'status' => TaskingRecipientStatus::class,
            'read_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'overdue_notified_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function auditExcept(): array
    {
        return ['overdue_notified_at'];
    }

    protected static function booted(): void
    {
        // Visible only while the tasking is (jurisdiction AND classification).
        static::addGlobalScope('tasking', fn (Builder $query) => $query->whereHas('tasking'));
    }

    public function isCompleted(): bool
    {
        return $this->status === TaskingRecipientStatus::Completed;
    }

    /** Not completed by the tasking's due date. */
    public function isOverdue(Tasking $tasking, ?CarbonImmutable $now = null): bool
    {
        return ! $this->isCompleted() && $tasking->isPastDue($now);
    }

    /**
     * @param  Builder<TaskingRecipient>  $query
     * @return Builder<TaskingRecipient>
     */
    public function scopeOverdue(Builder $query, ?CarbonImmutable $now = null): Builder
    {
        return $query->where('tasking_recipients.status', '<>', TaskingRecipientStatus::Completed->value)
            ->whereHas('tasking', fn (Builder $t) => $t->where('taskings.status', 'sent')
                ->whereNotNull('taskings.response_due_at')
                ->where('taskings.response_due_at', '<=', $now ?? CarbonImmutable::now()));
    }

    /**
     * @return BelongsTo<Tasking, $this>
     */
    public function tasking(): BelongsTo
    {
        return $this->belongsTo(Tasking::class);
    }

    /**
     * @return BelongsTo<Lga, $this>
     */
    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    /**
     * @return HasMany<TaskingResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(TaskingResponse::class);
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
    public function reader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'read_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
