<?php

namespace App\Models;

use App\Support\ReferenceData\ReferenceTable;
use Database\Factories\PropertyTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Reference data (Phase 2A): deactivated, never deleted; code fixed once
 * created. Not jurisdiction-scoped: everyone reads reference data.
 */
class PropertyType extends ReferenceModel
{
    /** @use HasFactory<PropertyTypeFactory> */
    use HasFactory;

    protected $fillable = ['name', 'code', 'description', 'sort_order', 'is_active'];

    protected $hidden = ['id', 'created_by', 'updated_by'];

    public static function referenceTable(): ReferenceTable
    {
        return ReferenceTable::PropertyTypes;
    }

    protected function casts(): array
    {
        return [
            ...$this->referenceCasts(),
        ];
    }
}
