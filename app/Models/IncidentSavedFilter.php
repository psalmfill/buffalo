<?php

namespace App\Models;

use App\Models\Concerns\HasUlidRouteKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's named incident filter set (Phase 5D). Each user sees and manages
 * only their own; the filters are ULIDs and enum values, applied through
 * the viewer's scopes like any filter URL.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $name
 * @property array<string, mixed> $filters
 * @property CarbonImmutable|null $created_at
 */
class IncidentSavedFilter extends Model
{
    use HasUlidRouteKey;

    protected $fillable = ['name', 'filters'];

    protected $hidden = ['id', 'user_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['filters' => 'array'];
    }
}
