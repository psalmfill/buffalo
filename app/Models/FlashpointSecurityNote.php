<?php

namespace App\Models;

use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A link between a flashpoint and a community security note (Phase 6A). The row sits on
 * the flashpoint's place (cascading composite FK) and is visible only when
 * BOTH the flashpoint and the linked record are visible to the viewer:
 * counts through this model never include hidden links. Unlinking
 * soft-deletes the row; both are named audit events on the flashpoint.
 *
 * @property int $id
 * @property string $ulid
 * @property int $flashpoint_id
 * @property int $state_id
 * @property int $lga_id
 * @property int $community_security_note_id
 * @property string|null $link_note
 * @property int|null $linked_by
 * @property CarbonImmutable $linked_at
 * @property int|null $unlinked_by
 * @property-read Flashpoint $flashpoint
 * @property-read CommunitySecurityNote $securityNote
 * @property-read User|null $linker
 */
class FlashpointSecurityNote extends Model implements JurisdictionScoped
{
    use HasJurisdiction;
    use HasUlidRouteKey;
    use SoftDeletes;

    public $timestamps = false;

    protected $table = 'flashpoint_security_notes';

    protected $guarded = ['*'];

    protected $hidden = ['id', 'flashpoint_id', 'state_id', 'lga_id', 'community_security_note_id', 'linked_by', 'unlinked_by'];

    protected static function booted(): void
    {
        static::addGlobalScope('both_visible', fn (Builder $query) => $query->whereHas('flashpoint')->whereHas('securityNote'));
    }

    protected function casts(): array
    {
        return [
            'linked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Flashpoint, $this>
     */
    public function flashpoint(): BelongsTo
    {
        return $this->belongsTo(Flashpoint::class);
    }

    /**
     * @return BelongsTo<CommunitySecurityNote, $this>
     */
    public function securityNote(): BelongsTo
    {
        return $this->belongsTo(CommunitySecurityNote::class, 'community_security_note_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function linker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }
}
