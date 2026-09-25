<?php

namespace App\Models;

use App\Enums\AgencyType;
use App\Support\ReferenceData\ReferenceTable;
use Database\Factories\SecurityAgencyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reference data (Phase 2A): deactivated, never deleted; code fixed once
 * created. Not jurisdiction-scoped: everyone reads reference data.
 *
 * @property string $short_name
 * @property AgencyType $agency_type
 * @property int|null $state_id
 * @property-read State|null $state
 */
class SecurityAgency extends ReferenceModel
{
    /** @use HasFactory<SecurityAgencyFactory> */
    use HasFactory;

    protected $fillable = ['name', 'code', 'description', 'sort_order', 'is_active', 'short_name', 'agency_type', 'state_id'];

    protected $hidden = ['id', 'created_by', 'updated_by', 'state_id'];

    public static function referenceTable(): ReferenceTable
    {
        return ReferenceTable::SecurityAgencies;
    }

    protected function casts(): array
    {
        return [
            ...$this->referenceCasts(),
            'agency_type' => AgencyType::class,
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
     * @return array<string, mixed>
     */
    protected function optionExtras(): array
    {
        return [
            'short_name' => $this->short_name,
            'agency_type' => $this->agency_type->value,
            'state' => $this->state?->ulid,
        ];
    }
}
