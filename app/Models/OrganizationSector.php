<?php

namespace App\Models;

use App\Support\ReferenceData\ReferenceTable;
use Database\Factories\OrganizationSectorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Sectors of organizations (Phase 4B): an administrator-extensible lookup.
 */
class OrganizationSector extends ReferenceModel
{
    /** @use HasFactory<OrganizationSectorFactory> */
    use HasFactory;

    protected $fillable = ['name', 'code', 'description', 'sort_order', 'is_active'];

    protected $hidden = ['id', 'created_by', 'updated_by'];

    public static function referenceTable(): ReferenceTable
    {
        return ReferenceTable::OrganizationSectors;
    }

    protected function casts(): array
    {
        return [
            ...$this->referenceCasts(),
        ];
    }
}
