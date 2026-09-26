<?php

namespace App\Models;

use App\Enums\ReligiousInstitutionType;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Database\Factories\ReligiousInstitutionFactory;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A church, mosque, shrine or religious school in a community (Phase 2C).
 * Jurisdiction-scoped; not classified (always restricted). "Number and
 * types of religious groups" are computed from these rows, never stored.
 *
 * @property int $id
 * @property string $ulid
 * @property int $state_id
 * @property int $lga_id
 * @property int $community_id
 * @property int $religion_id
 * @property int|null $denomination_id
 * @property string $name
 * @property ReligiousInstitutionType $type
 * @property int|null $estimated_congregation
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property float|null $latitude only with withCoordinates()
 * @property float|null $longitude only with withCoordinates()
 * @property-read Lga $lga
 * @property-read Community $community
 * @property-read Religion $religion
 * @property-read Denomination|null $denomination
 */
class ReligiousInstitution extends Model implements JurisdictionScoped
{
    /** @use HasFactory<ReligiousInstitutionFactory> */
    use HasFactory;

    use HasJurisdiction;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use SoftDeletes;

    protected $fillable = ['name', 'type', 'estimated_congregation', 'notes'];

    protected $hidden = ['id', 'state_id', 'lga_id', 'community_id', 'religion_id', 'denomination_id', 'location', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'type' => ReligiousInstitutionType::class,
            'estimated_congregation' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * @return list<string>
     */
    protected function auditExcept(): array
    {
        return ['location'];
    }

    public static function pointFrom(?float $latitude, ?float $longitude): ?Expression
    {
        return Community::pointFrom($latitude, $longitude);
    }

    /**
     * @param  Builder<ReligiousInstitution>  $query
     * @return Builder<ReligiousInstitution>
     */
    public function scopeWithCoordinates(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('religious_institutions.*');
        }

        return $query->selectRaw('ST_Y(religious_institutions.location::geometry) AS latitude, ST_X(religious_institutions.location::geometry) AS longitude');
    }

    /**
     * @return BelongsTo<Lga, $this>
     */
    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    /**
     * @return BelongsTo<Community, $this>
     */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    /**
     * @return BelongsTo<Religion, $this>
     */
    public function religion(): BelongsTo
    {
        return $this->belongsTo(Religion::class);
    }

    /**
     * @return BelongsTo<Denomination, $this>
     */
    public function denomination(): BelongsTo
    {
        return $this->belongsTo(Denomination::class);
    }
}
