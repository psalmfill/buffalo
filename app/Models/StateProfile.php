<?php

namespace App\Models;

use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasRichText;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use App\Support\Jurisdiction\Contracts\LgaVisibilityRule;
use Database\Factories\StateProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A state's reference profile (Phase 11F): one per state, created on first
 * edit. Jurisdiction-scoped by state_id (a state-level record).
 *
 * An LGA chief reads their own state's profile (LgaVisibilityRule): it is
 * general background for an LGA officer. Editing stays with state-level and
 * national roles (StatePolicy::updateProfile).
 *
 * @property int $id
 * @property string $ulid
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
 * @property-read State $state
 * @property-read User|null $reviewer
 */
class StateProfile extends Model implements JurisdictionScoped, LgaVisibilityRule
{
    /** @use HasFactory<StateProfileFactory> */
    use HasFactory;

    use HasJurisdiction;
    use HasRichText;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;
    use SoftDeletes;

    /** State-level records: no LGA column. */
    protected ?string $jurisdictionLgaColumn = null;

    protected $fillable = [
        'history',
        'economic_activities',
        'terrain',
        'major_roads',
        'waterways',
        'borders',
        'general_notes',
    ];

    protected $hidden = ['id', 'state_id', 'last_reviewed_by', 'created_by', 'updated_by'];

    /**
     * Rich text (Phase 11B), sanitised on every save.
     *
     * @return list<string>
     */
    public function richTextFields(): array
    {
        return ['history', 'economic_activities', 'major_roads', 'waterways', 'borders', 'general_notes'];
    }

    protected function casts(): array
    {
        return [
            'terrain' => 'array',
            'last_reviewed_at' => 'datetime',
        ];
    }

    public function applyLgaVisibility(Builder $query, int $lgaId): void
    {
        $query->whereIn($this->qualifyColumn('state_id'), Lga::query()->whereKey($lgaId)->select('state_id'));
    }

    public function visibleToLga(int $lgaId): bool
    {
        return $this->state_id === Lga::query()->whereKey($lgaId)->value('state_id');
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_reviewed_by');
    }
}
