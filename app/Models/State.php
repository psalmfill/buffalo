<?php

namespace App\Models;

use App\Models\Concerns\HasActiveScope;
use App\Models\Concerns\HasUlidRouteKey;
use Database\Factories\StateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * A state (or the FCT). `code` is the ISO 3166-2:NG suffix, e.g. "LA".
 * Reference data: not jurisdiction-scoped, deactivated rather than deleted.
 *
 * @property int $id
 * @property string $ulid
 * @property int $geopolitical_zone_id
 * @property string $code
 * @property string $name
 * @property string|null $capital
 * @property bool $is_active
 */
class State extends Model
{
    use HasActiveScope;

    /** @use HasFactory<StateFactory> */
    use HasFactory;

    use HasUlidRouteKey;

    protected $fillable = [
        'geopolitical_zone_id',
        'code',
        'name',
        'capital',
        'is_active',
    ];

    protected $hidden = [
        'id',
        'geopolitical_zone_id',
        'centroid',
        'boundary',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<GeopoliticalZone, $this>
     */
    public function geopoliticalZone(): BelongsTo
    {
        return $this->belongsTo(GeopoliticalZone::class);
    }

    /**
     * @return HasMany<SenatorialDistrict, $this>
     */
    public function senatorialDistricts(): HasMany
    {
        return $this->hasMany(SenatorialDistrict::class);
    }

    /**
     * LGAs through the state's senatorial districts. Every LGA also stores
     * state_id directly; the composite foreign key on lgas guarantees both
     * paths agree.
     *
     * @return HasManyThrough<Lga, SenatorialDistrict, $this>
     */
    public function lgas(): HasManyThrough
    {
        return $this->hasManyThrough(Lga::class, SenatorialDistrict::class);
    }

    /**
     * @return HasMany<Ward, $this>
     */
    public function wards(): HasMany
    {
        return $this->hasMany(Ward::class);
    }
}
