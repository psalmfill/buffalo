<?php

namespace App\Models;

use App\Enums\Classification;
use App\Enums\SecurityNoteCategory;
use App\Enums\SecurityNoteStatus;
use App\Models\Concerns\HasClassification;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Models\Concerns\LogsReads;
use App\Support\Classification\Contracts\Classified;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Database\Factories\CommunitySecurityNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A security-related note about a community (Phase 2C): an early form of
 * intelligence. Jurisdiction-scoped AND classified (both scopes apply).
 * Reads of confidential and secret notes are access-logged.
 *
 * @property int $id
 * @property string $ulid
 * @property int $state_id
 * @property int $lga_id
 * @property int $community_id
 * @property SecurityNoteCategory $category
 * @property string $title
 * @property string $description
 * @property SecurityNoteStatus $status
 * @property Classification $classification
 * @property Carbon $observed_at
 * @property Carbon|null $resolved_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property-read Lga $lga
 * @property-read Community $community
 * @property-read User|null $creator
 */
class CommunitySecurityNote extends Model implements Classified, JurisdictionScoped
{
    use HasClassification;

    /** @use HasFactory<CommunitySecurityNoteFactory> */
    use HasFactory;

    use HasJurisdiction;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use LogsReads;
    use SoftDeletes;

    protected $fillable = ['category', 'title', 'description', 'observed_at'];

    protected $hidden = ['id', 'state_id', 'lga_id', 'community_id', 'created_by', 'updated_by'];

    protected $attributes = [
        'classification' => 'restricted',
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'category' => SecurityNoteCategory::class,
            'status' => SecurityNoteStatus::class,
            'classification' => Classification::class,
            'observed_at' => 'date',
            'resolved_at' => 'date',
        ];
    }

    /** Whether reading this note must be access-logged. */
    public function requiresReadLogging(): bool
    {
        return $this->classificationLevel()->rank() >= Classification::Confidential->rank();
    }

    /**
     * @return BelongsTo<Lga, $this>
     */
    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    /**
     * @return BelongsTo<Community, $this>
     */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
