<?php

namespace App\Models;

use App\Enums\LoginEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One authentication event. Append-only: the database rejects updates and
 * deletes (trigger), and the model refuses them too. Written only through
 * App\Support\Auth\LoginHistoryRecorder. Viewer: Phase 1D.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $email_attempted
 * @property LoginEvent $event
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 */
class LoginHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'email_attempted', 'event', 'ip_address', 'user_agent'];

    protected $hidden = ['id', 'user_id'];

    protected function casts(): array
    {
        return [
            'event' => LoginEvent::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Login history is append-only.'));
        static::deleting(fn () => throw new LogicException('Login history is append-only.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
