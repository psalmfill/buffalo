<?php

namespace App\Models;

use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsReads;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * An analytics chart export (Phase 7C): PNG or CSV, the subject of its
 * record_access_logs entry. Append-only (trigger).
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $area
 * @property string $chart
 * @property string $format
 * @property array<string, mixed> $filters
 * @property CarbonImmutable $created_at
 */
class AnalyticsExport extends Model
{
    use HasUlidRouteKey;
    use LogsReads;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'user_id'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
