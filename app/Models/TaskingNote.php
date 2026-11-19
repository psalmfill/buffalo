<?php

namespace App\Models;

use App\Enums\TaskingStatus;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasRichText;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use App\Support\Jurisdiction\Contracts\LgaVisibilityRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A follow-up note on a sent tasking (Phase 11H), by a director of the
 * state; append-only (trigger). Seen by everyone who sees the tasking: the
 * state's directors, national oversight, and each recipient LGA's chief.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tasking_id
 * @property int $state_id
 * @property string $body
 * @property string $body_text
 * @property int $created_by
 * @property CarbonImmutable $created_at
 * @property-read Tasking|null $tasking
 * @property-read User $author
 */
class TaskingNote extends Model implements JurisdictionScoped, LgaVisibilityRule
{
    use HasJurisdiction;
    use HasRichText;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;

    public const UPDATED_AT = null;

    protected ?string $jurisdictionLgaColumn = null;

    protected $hidden = ['id', 'tasking_id', 'state_id', 'created_by'];

    /**
     * @return list<string>
     */
    public function richTextFields(): array
    {
        return ['body'];
    }

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('tasking', fn (Builder $query) => $query->whereHas('tasking'));
    }

    public function applyLgaVisibility(Builder $query, int $lgaId): void
    {
        $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('tasking_recipients')
            ->join('taskings', 'taskings.id', '=', 'tasking_recipients.tasking_id')
            ->whereColumn('tasking_recipients.tasking_id', $this->qualifyColumn('tasking_id'))
            ->where('taskings.status', '<>', TaskingStatus::Draft->value)
            ->where('tasking_recipients.lga_id', $lgaId));
    }

    public function visibleToLga(int $lgaId): bool
    {
        return DB::table('tasking_recipients')->where('tasking_id', $this->tasking_id)->where('lga_id', $lgaId)->exists();
    }

    /**
     * @return BelongsTo<Tasking, $this>
     */
    public function tasking(): BelongsTo
    {
        return $this->belongsTo(Tasking::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
