<?php

namespace App\Models;

use App\Models\Concerns\HasActiveScope;
use App\Models\Concerns\HasUlidRouteKey;
use Database\Factories\GeopoliticalZoneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * One of Nigeria's six geopolitical zones. Reference data: not
 * jurisdiction-scoped, deactivated rather than deleted.
 *
 * @property int $id
 * @property string $ulid
 * @property string $code
 * @property string $name
 * @property bool $is_active
 */
class GeopoliticalZone extends Model
{
    use HasActiveScope;

    /** @use HasFactory<GeopoliticalZoneFactory> */
    use HasFactory;

    use HasUlidRouteKey;

    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    protected $hidden = [
        'id',
        'pivot',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<State, $this>
     */
    public function states(): HasMany
    {
        return $this->hasMany(State::class);
    }

    /**
     * @return HasManyThrough<Lga, State, $this>
     */
    public function lgas(): HasManyThrough
    {
        return $this->hasManyThrough(Lga::class, State::class);
    }
}
