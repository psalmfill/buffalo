<?php

namespace App\Models;

use App\Enums\IncidentCategory;
use App\Enums\Severity;
use App\Support\ReferenceData\ReferenceTable;
use Database\Factories\IncidentTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Reference data (Phase 2A): deactivated, never deleted; code fixed once
 * created. Not jurisdiction-scoped: everyone reads reference data.
 *
 * @property IncidentCategory $category
 * @property Severity $default_severity
 * @property list<array<string, mixed>> $details_schema
 * @property int $schema_version
 */
class IncidentType extends ReferenceModel
{
    /** @use HasFactory<IncidentTypeFactory> */
    use HasFactory;

    protected $fillable = ['name', 'code', 'description', 'sort_order', 'is_active', 'category', 'default_severity'];

    protected $hidden = ['id', 'created_by', 'updated_by'];

    public static function referenceTable(): ReferenceTable
    {
        return ReferenceTable::IncidentTypes;
    }

    protected function casts(): array
    {
        return [
            ...$this->referenceCasts(),
            'category' => IncidentCategory::class,
            'default_severity' => Severity::class,
            'details_schema' => 'array',
            'schema_version' => 'integer',
        ];
    }

    /**
     * The detail fields (details_schema) are changed only through
     * App\Actions\ReferenceData\UpdateIncidentTypeSchema, never mass
     * assigned.
     *
     * @return array<string, mixed>
     */
    protected function optionExtras(): array
    {
        return [
            'category' => $this->category->value,
            'default_severity' => $this->default_severity->value,
            'details_schema' => $this->details_schema,
            'schema_version' => $this->schema_version,
        ];
    }
}
