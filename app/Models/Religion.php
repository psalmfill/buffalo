<?php

namespace App\Models;

use App\Support\ReferenceData\ReferenceTable;
use Database\Factories\ReligionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reference data (Phase 2A): deactivated, never deleted; code fixed once
 * created. Not jurisdiction-scoped: everyone reads reference data.
 */
class Religion extends ReferenceModel
{
    /** @use HasFactory<ReligionFactory> */
    use HasFactory;

    protected $fillable = ['name', 'code', 'description', 'sort_order', 'is_active'];

    protected $hidden = ['id', 'created_by', 'updated_by'];

    public static function referenceTable(): ReferenceTable
    {
        return ReferenceTable::Religions;
    }

    protected function casts(): array
    {
        return [
            ...$this->referenceCasts(),
        ];
    }

    /**
     * @return HasMany<Denomination, $this>
     */
    public function denominations(): HasMany
    {
        return $this->hasMany(Denomination::class);
    }
}
