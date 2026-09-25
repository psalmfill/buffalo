<?php

namespace App\Models;

use App\Support\ReferenceData\ReferenceTable;
use Database\Factories\DenominationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reference data (Phase 2A): deactivated, never deleted; code fixed once
 * created. Not jurisdiction-scoped: everyone reads reference data.
 *
 * @property int $religion_id
 * @property-read Religion $religion
 */
class Denomination extends ReferenceModel
{
    /** @use HasFactory<DenominationFactory> */
    use HasFactory;

    protected $fillable = ['name', 'code', 'description', 'sort_order', 'is_active', 'religion_id'];

    protected $hidden = ['id', 'created_by', 'updated_by', 'religion_id'];

    public static function referenceTable(): ReferenceTable
    {
        return ReferenceTable::Denominations;
    }

    protected function casts(): array
    {
        return [
            ...$this->referenceCasts(),
        ];
    }

    /**
     * @return BelongsTo<Religion, $this>
     */
    public function religion(): BelongsTo
    {
        return $this->belongsTo(Religion::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function optionExtras(): array
    {
        return ['religion' => $this->religion->ulid];
    }
}
