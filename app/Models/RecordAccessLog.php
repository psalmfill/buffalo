<?php

namespace App\Models;

use App\Enums\AccessAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One read (view, export, print, download) of a sensitive record.
 * Append-only (database trigger); written only by RecordAccessLogger.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $subject_type
 * @property int $subject_id
 * @property AccessAction $action
 * @property string|null $context
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 */
class RecordAccessLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'subject_type', 'subject_id', 'action', 'context', 'ip_address', 'user_agent'];

    protected $hidden = ['id', 'user_id', 'subject_id'];

    protected function casts(): array
    {
        return ['action' => AccessAction::class];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The record access log is append-only.'));
        static::deleting(fn () => throw new LogicException('The record access log is append-only.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
