<?php

namespace App\Models;

use App\Models\Concerns\HasUlidRouteKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A UAT feedback note (Phase 9B.5).
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $role
 * @property string $page_url
 * @property string $text
 * @property CarbonImmutable $created_at
 */
class UatFeedback extends Model
{
    use HasUlidRouteKey;

    protected $table = 'uat_feedback';

    public const UPDATED_AT = null;

    protected $hidden = ['id', 'user_id'];

    protected $fillable = ['role', 'page_url', 'text'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
