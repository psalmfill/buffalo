<?php

namespace App\Models;

use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasRichText;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A further report back from an LGA on a tasking it has completed
 * (Phase 11H); append-only (trigger). Scoped by its own state and LGA, like
 * its recipient row: an LGA chief sees only their LGA's responses.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tasking_id
 * @property int $tasking_recipient_id
 * @property int $state_id
 * @property int $lga_id
 * @property string $body
 * @property string $body_text
 * @property int $created_by
 * @property CarbonImmutable $created_at
 * @property-read TaskingRecipient|null $recipient
 * @property-read User $author
 */
class TaskingResponse extends Model implements JurisdictionScoped
{
    use HasJurisdiction;
    use HasRichText;
    use HasUlidRouteKey;
    use LogsBuffaloActivity;

    public const UPDATED_AT = null;

    protected $hidden = ['id', 'tasking_id', 'tasking_recipient_id', 'state_id', 'lga_id', 'created_by'];

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
        static::addGlobalScope('recipient', fn (Builder $query) => $query->whereHas('recipient'));
    }

    /**
     * @return BelongsTo<TaskingRecipient, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(TaskingRecipient::class, 'tasking_recipient_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
