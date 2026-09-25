<?php

namespace App\Models;

use App\Enums\IncidentCategory;
use App\Support\ReferenceData\ReferenceTable;
use Database\Factories\ThreatTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Reference data (Phase 2A): deactivated, never deleted; code fixed once
 * created. Not jurisdiction-scoped: everyone reads reference data.
 *
 * @property IncidentCategory $category
 */
class ThreatType extends ReferenceModel
{
    /** @use HasFactory<ThreatTypeFactory> */
    use HasFactory;

    protected $fillable = ['name', 'code', 'description', 'sort_order', 'is_active', 'category'];

    protected $hidden = ['id', 'created_by', 'updated_by'];

    public static function referenceTable(): ReferenceTable
    {
        return ReferenceTable::ThreatTypes;
    }

    protected function casts(): array
    {
        return [
            ...$this->referenceCasts(),
            'category' => IncidentCategory::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function optionExtras(): array
    {
        return ['category' => $this->category->value];
    }
}
