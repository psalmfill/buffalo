<?php

namespace App\Models;

use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Database\Factories\LgaProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An LGA's intelligence profile (Phase 2B): one per LGA, created on first
 * edit. Jurisdiction-scoped by state_id/lga_id.
 *
 * @property int $id
 * @property string $ulid
 * @property int $lga_id
 * @property int $state_id
 * @property string|null $history
 * @property string|null $economic_activities
 * @property list<string> $terrain
 * @property string|null $major_roads
 * @property string|null $waterways
 * @property string|null $borders
 * @property string|null $general_notes
 * @property Carbon|null $last_reviewed_at
 * @property int|null $last_reviewed_by
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property-read Lga $lga
 * @property-read User|null $reviewer
 */
class LgaProfile extends Model implements JurisdictionScoped
{
    /** @use HasFactory<LgaProfileFactory> */
    use HasFactory;

    use HasJurisdiction;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use SoftDeletes;

    protected $fillable = [
        'history',
        'economic_activities',
        'terrain',
        'major_roads',
        'waterways',
        'borders',
        'general_notes',
    ];

    protected $hidden = ['id', 'lga_id', 'state_id', 'last_reviewed_by', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'terrain' => 'array',
            'last_reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Lga, $this>
     */
    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_reviewed_by');
    }
}
