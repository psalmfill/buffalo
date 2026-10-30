<?php

namespace App\Models;

use App\Models\Concerns\HasActiveScope;
use App\Models\Concerns\HasHierarchyOrigin;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use Database\Factories\SenatorialDistrictFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A senatorial district. `code` is globally unique, format <STATE>-<X>.
 * Reference data: not jurisdiction-scoped, deactivated rather than deleted.
 *
 * @property int $id
 * @property string $ulid
 * @property int $state_id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 * @property string $origin "import" or "admin" (HasHierarchyOrigin)
 * @property int|null $created_by
 * @property string|null $created_reason
 */
class SenatorialDistrict extends Model
{
    use HasActiveScope;

    /** @use HasFactory<SenatorialDistrictFactory> */
    use HasFactory;

    use HasHierarchyOrigin;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;

    /**
     * Generated search column (Phase 7B).
     *
     * @return list<string>
     */
    protected function auditExcept(): array
    {
        return ['search_name'];
    }

    protected $fillable = [
        'state_id',
        'code',
        'name',
        'is_active',
    ];

    /** The column default, so new models audit cleanly (HasHierarchyOrigin). */
    protected $attributes = ['origin' => self::ORIGIN_IMPORT];

    protected $hidden = [
        'created_by',
        'search_name',
        'id',
        'state_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /**
     * @return HasMany<Lga, $this>
     */
    public function lgas(): HasMany
    {
        return $this->hasMany(Lga::class);
    }
}
