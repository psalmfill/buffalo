<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A previous password hash, kept to prevent reuse. Written only by
 * App\Actions\Auth\SetUserPassword.
 *
 * @property int $id
 * @property int $user_id
 * @property string $password
 * @property Carbon|null $created_at
 */
class PasswordHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['password'];

    protected $hidden = ['id', 'user_id', 'password'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
