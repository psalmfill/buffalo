<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A one-time account setup link (72 hours). Only the SHA-256 hash of the
 * token is stored.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property int|null $created_by
 */
class AccountSetupToken extends Model
{
    public const VALID_HOURS = 72;

    protected $fillable = ['user_id', 'token_hash', 'expires_at', 'created_by'];

    protected $hidden = ['id', 'user_id', 'token_hash', 'created_by'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @param  Builder<AccountSetupToken>  $query
     * @return Builder<AccountSetupToken>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
