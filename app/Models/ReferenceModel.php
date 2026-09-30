<?php

namespace App\Models;

use App\Models\Concerns\IsReferenceData;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for the lookup tables (Phase 2A): IncidentType, ThreatType,
 * SecurityAgency, Religion, Denomination, PropertyType and OrganizationSector.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property int $sort_order
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 */
abstract class ReferenceModel extends Model
{
    use IsReferenceData;
}
