<?php

namespace App\Models;

use App\Models\Concerns\HasActiveScope;
use App\Models\Concerns\HasHierarchyOrigin;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use Database\Factories\WardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A ward within an LGA. `state_id` is denormalised from the LGA and kept
 * consistent by a composite foreign key. Reference data: not
 * jurisdiction-scoped, deactivated rather than deleted.
 *
 * @property int $id
 * @property string $ulid
 * @property int $lga_id
 * @property int $state_id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 * @property string $origin "import" or "admin" (HasHierarchyOrigin)
 * @property int|null $created_by
 * @property string|null $created_reason
 */
class Ward extends Model
{
    use HasActiveScope;

    /** @use HasFactory<WardFactory> */
    use HasFactory;

    use HasHierarchyOrigin;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;

    protected $fillable = [
        'lga_id',
        'state_id',
        'code',
        'name',
        'is_active',
    ];

    /** The column default, so new models audit cleanly (HasHierarchyOrigin). */
    protected $attributes = ['origin' => self::ORIGIN_IMPORT];

    protected $hidden = [
        'created_by',
        'id',
        'lga_id',
        'state_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Lga, $this>
     */
    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /**
     * Communities in the ward (jurisdiction-scoped).
     *
     * @return HasMany<Community, $this>
     */
    public function communities(): HasMany
    {
        return $this->hasMany(Community::class);
    }
}
