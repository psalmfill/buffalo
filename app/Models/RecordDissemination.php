<?php

namespace App\Models;

use App\Enums\DisseminationTargetLevel;
use App\Enums\Role;
use App\Models\Concerns\HasUlidRouteKey;
use App\Support\Jurisdiction\Contracts\Disseminable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;

/**
 * Read-only access to a threat report or flashpoint pushed by its owning
 * state to another state or LGA (Phase 7A). Active: not revoked and not
 * expired. Read by DisseminationResolver, which lets the subject through
 * the jurisdiction scope, never the classification scope.
 *
 * Not jurisdiction-scoped itself: its places are the source and the target,
 * not one location. Pages read rows only through scopeVisibleTo() and
 * DisseminationPolicy. Every create, acknowledgement, renewal, revocation
 * and expiry is a named audit event on this row (Phase 7A actions).
 *
 * @property int $id
 * @property string $ulid
 * @property string $subject_type
 * @property int $subject_id
 * @property int $source_state_id
 * @property int $source_lga_id
 * @property DisseminationTargetLevel $target_level
 * @property int $target_state_id
 * @property int|null $target_lga_id
 * @property int|null $parent_dissemination_id
 * @property string $reason
 * @property int $disseminated_by
 * @property CarbonImmutable $disseminated_at
 * @property CarbonImmutable $expires_at
 * @property int|null $acknowledged_by
 * @property CarbonImmutable|null $acknowledged_at
 * @property int|null $revoked_by
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revocation_reason
 * @property CarbonImmutable|null $reminded_at
 * @property CarbonImmutable|null $expiry_notified_at
 * @property-read State $sourceState
 * @property-read Lga $sourceLga
 * @property-read State $targetState
 * @property-read Lga|null $targetLga
 * @property-read RecordDissemination|null $parent
 * @property-read User $disseminator
 * @property-read User|null $acknowledger
 */
class RecordDissemination extends Model
{
    use HasUlidRouteKey;

    /** @var array<string, class-string<Model&Disseminable>> */
    public const SUBJECTS = [
        'threat_report' => ThreatReport::class,
        'flashpoint' => Flashpoint::class,
    ];

    protected $guarded = ['*'];

    protected $hidden = [
        'id', 'subject_id', 'source_state_id', 'source_lga_id', 'target_state_id', 'target_lga_id',
        'parent_dissemination_id', 'disseminated_by', 'acknowledged_by', 'revoked_by',
    ];

    protected function casts(): array
    {
        return [
            'target_level' => DisseminationTargetLevel::class,
            'disseminated_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'reminded_at' => 'immutable_datetime',
            'expiry_notified_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<RecordDissemination>  $query
     * @return Builder<RecordDissemination>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('record_disseminations.revoked_at')
            ->where('record_disseminations.expires_at', '>', CarbonImmutable::now());
    }

    /**
     * @param  Builder<RecordDissemination>  $query
     * @return Builder<RecordDissemination>
     */
    public function scopeAbout(Builder $query, Model&Disseminable $subject): Builder
    {
        return $query->where('record_disseminations.subject_type', $subject->disseminationType())
            ->where('record_disseminations.subject_id', $subject->getKey());
    }

    /**
     * Disseminations that reach the user as a recipient: those targeting
     * their state (State Director and Deputy: state- and LGA-level targets
     * in that state, of records from other states) or their LGA (LGA
     * chief). Other roles receive none.
     *
     * @param  Builder<RecordDissemination>  $query
     * @return Builder<RecordDissemination>
     */
    public function scopeReceivedBy(Builder $query, User $user): Builder
    {
        $base = $query->getQuery();
        self::applyReceivedBy($base, $user);

        return $query;
    }

    /**
     * The recipient condition on a base query of record_disseminations
     * (shared with DisseminationResolver, which must not boot this model's
     * scopes inside the jurisdiction scope).
     */
    public static function applyReceivedBy(QueryBuilder $query, User $user): void
    {
        $role = $user->getRole();

        if (in_array($role, [Role::StateDirector, Role::DeputyStateDirector], true) && $user->state_id !== null) {
            // Records of their own state are theirs already (e.g. shared
            // with one of its LGAs): they are owners, not recipients.
            $query->where('record_disseminations.target_state_id', $user->state_id)
                ->where('record_disseminations.source_state_id', '<>', $user->state_id);

            return;
        }

        if ($role === Role::LgaSecurityChief && $user->lga_id !== null) {
            $query->where('record_disseminations.target_level', DisseminationTargetLevel::Lga->value)
                ->where('record_disseminations.target_lga_id', $user->lga_id);

            return;
        }

        $query->whereRaw('1 = 0');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->revoked_at === null && ! $this->expires_at->isFuture();
    }

    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            ! $this->expires_at->isFuture() => 'expired',
            $this->acknowledged_at !== null => 'acknowledged',
            default => 'unacknowledged',
        };
    }

    /**
     * The disseminated record, through the CURRENT viewer's scopes (null
     * when the viewer cannot see it).
     */
    public function subject(): (Model&Disseminable)|null
    {
        $class = self::SUBJECTS[$this->subject_type] ?? throw new LogicException("Unknown dissemination subject {$this->subject_type}.");

        /** @var (Model&Disseminable)|null */
        return $class::query()->whereKey($this->subject_id)->first();
    }

    /**
     * @return class-string<Model&Disseminable>
     */
    public static function subjectClass(string $type): string
    {
        return self::SUBJECTS[$type] ?? throw new LogicException("Unknown dissemination subject {$type}.");
    }

    public function targetName(): string
    {
        return $this->target_level === DisseminationTargetLevel::Lga
            ? ($this->targetLga->name ?? '').' LGA, '.$this->targetState->name
            : $this->targetState->name.' State';
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function sourceState(): BelongsTo
    {
        return $this->belongsTo(State::class, 'source_state_id');
    }

    /**
     * @return BelongsTo<Lga, $this>
     */
    public function sourceLga(): BelongsTo
    {
        return $this->belongsTo(Lga::class, 'source_lga_id');
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function targetState(): BelongsTo
    {
        return $this->belongsTo(State::class, 'target_state_id');
    }

    /**
     * @return BelongsTo<Lga, $this>
     */
    public function targetLga(): BelongsTo
    {
        return $this->belongsTo(Lga::class, 'target_lga_id');
    }

    /**
     * @return BelongsTo<RecordDissemination, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_dissemination_id');
    }

    /**
     * @return HasMany<RecordDissemination, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_dissemination_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function disseminator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disseminated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
