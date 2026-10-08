<?php

namespace App\Models;

use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A duplicate incident's content consolidated into the original (Phase
 * 10B.1). Append-only and hash-chained; its place follows the original.
 * Not undoable: the duplicate is invalid and locked afterwards.
 *
 * @property int $id
 * @property string $ulid
 * @property int $original_incident_id
 * @property int $duplicate_incident_id
 * @property int $state_id
 * @property int $lga_id
 * @property array<string, mixed> $manifest
 * @property int $consolidated_by
 * @property CarbonImmutable $consolidated_at
 */
class IncidentConsolidation extends Model implements JurisdictionScoped
{
    use HasJurisdiction;
    use HasUlidRouteKey;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'original_incident_id', 'duplicate_incident_id', 'state_id', 'lga_id', 'manifest', 'consolidated_by', 'chain_seq', 'prev_hash', 'row_hash'];

    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'consolidated_at' => 'immutable_datetime',
        ];
    }
}
