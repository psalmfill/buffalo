<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Districts, LGAs and wards record whether they came from the official CSV
 * import (`origin` "import") or were created in Administration › Hierarchy
 * ("admin"), with who created them on screen and the reason they gave.
 */
trait HasHierarchyOrigin
{
    public const ORIGIN_IMPORT = 'import';

    public const ORIGIN_ADMIN = 'admin';

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function createdOnScreen(): bool
    {
        return $this->getAttribute('origin') === self::ORIGIN_ADMIN;
    }
}
