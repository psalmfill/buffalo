<?php

namespace App\Models;

use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use App\Support\Jurisdiction\Contracts\LgaVisibilityRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One executed person merge (Phase 10A.2), with the manifest that undoes
 * it. Append-only and hash-chained: only the undo columns are ever set,
 * and survivor_prior_values is erased once the undo window has passed.
 *
 * survivor_prior_values (Crypt-encrypted) is hidden from serialisation and
 * never logged or sent to the browser; only MergePersons writes it and only
 * UndoPersonMerge reads it.
 *
 * Jurisdiction: the state of both records; an LGA chief sees the merges of
 * survivors in their own LGA.
 *
 * @property int $id
 * @property string $ulid
 * @property int $state_id
 * @property int $survivor_person_id
 * @property int $source_person_id
 * @property int|null $request_id
 * @property array<string, string> $field_choices
 * @property array<string, mixed> $manifest
 * @property string|null $survivor_prior_values
 * @property int $executed_by
 * @property CarbonImmutable $executed_at
 * @property int|null $undone_by
 * @property CarbonImmutable|null $undone_at
 * @property string|null $undo_reason
 * @property-read Person|null $survivor
 * @property-read Person|null $source
 * @property-read User $executor
 * @property-read User|null $undoer
 */
class PersonMerge extends Model implements JurisdictionScoped, LgaVisibilityRule
{
    use HasJurisdiction;
    use HasUlidRouteKey;

    public $timestamps = false;

    protected $hidden = [
        'id', 'state_id', 'survivor_person_id', 'source_person_id', 'request_id', 'executed_by', 'undone_by',
        'manifest', 'survivor_prior_values', 'chain_seq', 'prev_hash', 'row_hash',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'field_choices' => 'array',
            'manifest' => 'array',
            'executed_at' => 'immutable_datetime',
            'undone_at' => 'immutable_datetime',
        ];
    }

    public function applyLgaVisibility(Builder $query, int $lgaId): void
    {
        $query->whereIn($this->qualifyColumn('survivor_person_id'), fn ($ids) => $ids->select('id')->from('persons')->where('lga_id', $lgaId));
    }

    public function visibleToLga(int $lgaId): bool
    {
        return DB::table('persons')->where('id', $this->survivor_person_id)->where('lga_id', $lgaId)->exists();
    }

    public function isUndone(): bool
    {
        return $this->undone_at !== null;
    }

    /** The end of the undo window. */
    public function undoDeadline(): CarbonImmutable
    {
        return $this->executed_at->addDays((int) config('buffalo.merges.undo_days', 30));
    }

    /**
     * Within jurisdiction and classification, as the viewer sees it; null
     * when the viewer cannot see the person.
     *
     * @return BelongsTo<Person, $this>
     */
    public function survivor(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'survivor_person_id');
    }

    /**
     * The source is soft-deleted (merged).
     *
     * @return BelongsTo<Person, $this>
     */
    public function source(): BelongsTo
    {
        /** @var BelongsTo<Person, $this> */
        return $this->belongsTo(Person::class, 'source_person_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function undoer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by');
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }
}
