<?php

namespace App\Models;

use App\Support\ReferenceData\ReferenceTable;
use Database\Factories\OffenceTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An offence an arrested person is held for (Phase 11C). Reference data
 * (Phase 2A): deactivated, never deleted; code fixed once created. The
 * optional threat type maps the offence to a threat category in analytics.
 * Not jurisdiction-scoped: everyone reads reference data.
 *
 * @property int|null $threat_type_id
 * @property-read ThreatType|null $threatType
 */
class OffenceType extends ReferenceModel
{
    /** @use HasFactory<OffenceTypeFactory> */
    use HasFactory;

    protected $fillable = ['name', 'code', 'description', 'sort_order', 'is_active', 'threat_type_id'];

    protected $hidden = ['id', 'created_by', 'updated_by', 'threat_type_id'];

    public static function referenceTable(): ReferenceTable
    {
        return ReferenceTable::OffenceTypes;
    }

    protected function casts(): array
    {
        return $this->referenceCasts();
    }

    /**
     * @return BelongsTo<ThreatType, $this>
     */
    public function threatType(): BelongsTo
    {
        return $this->belongsTo(ThreatType::class);
    }
}
