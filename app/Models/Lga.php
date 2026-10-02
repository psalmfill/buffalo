<?php

namespace App\Models;

use App\Models\Concerns\HasActiveScope;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use Database\Factories\LgaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A Local Government Area. `code` is three uppercase letters, unique within
 * the state. Reference data: not jurisdiction-scoped, deactivated rather than
 * deleted.
 *
 * @property int $id
 * @property string $ulid
 * @property int $state_id
 * @property int $senatorial_district_id
 * @property string $code
 * @property string $name
 * @property string|null $headquarters
 * @property string|null $area_km2
 * @property int|null $population_estimate
 * @property bool $is_active
 */
class Lga extends Model
{
    use HasActiveScope;

    /** @use HasFactory<LgaFactory> */
    use HasFactory;

    use HasUlidRouteKey;
    use LogsBuffaloActivity;

    protected $fillable = [
        'state_id',
        'senatorial_district_id',
        'code',
        'name',
        'headquarters',
        'area_km2',
        'population_estimate',
        'is_active',
    ];

    protected $hidden = [
        'search_name',
        'id',
        'state_id',
        'senatorial_district_id',
        'centroid',
        'boundary',
    ];

    /**
     * Geometry is too large and binary for the audit log.
     *
     * @return list<string>
     */
    protected function auditExcept(): array
    {
        return ['centroid', 'boundary', 'search_name'];
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'area_km2' => 'decimal:2',
            'population_estimate' => 'integer',
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
     * @return BelongsTo<SenatorialDistrict, $this>
     */
    public function senatorialDistrict(): BelongsTo
    {
        return $this->belongsTo(SenatorialDistrict::class);
    }

    /**
     * @return HasMany<Ward, $this>
     */
    public function wards(): HasMany
    {
        return $this->hasMany(Ward::class);
    }

    /**
     * The intelligence profile (Phase 2B), jurisdiction-scoped.
     *
     * @return HasOne<LgaProfile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(LgaProfile::class);
    }

    /**
     * @return HasMany<Community, $this>
     */
    public function communities(): HasMany
    {
        return $this->hasMany(Community::class);
    }
}
