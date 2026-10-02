<?php

namespace App\Services\Reports\Builders;

use App\Models\Lga;
use App\Models\RecordDissemination;
use App\Models\State;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The optional state or LGA a report is narrowed to (Phase 7D); always
 * within the requester's reach (checked by ReportParameters, and the
 * scopes apply anyway).
 */
final readonly class Places
{
    private function __construct(public ?State $state, public ?Lga $lga) {}

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function from(array $parameters): self
    {
        $lga = is_string($parameters['lga'] ?? null) ? Lga::query()->where('ulid', $parameters['lga'])->first() : null;
        $state = ($lga !== null ? $lga->state : null) ?? (is_string($parameters['state'] ?? null) ? State::query()->where('ulid', $parameters['state'])->first() : null);

        return new self($state, $lga);
    }

    public function label(User $user): string
    {
        return match (true) {
            $this->lga !== null => $this->lga->name.' LGA, '.$this->lga->state->name.' State',
            $this->state !== null => $this->state->name.' State',
            default => $user->jurisdictionLabel(),
        };
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function narrow(Builder $query, string $table): Builder
    {
        return match (true) {
            $this->lga !== null => $query->where("{$table}.lga_id", $this->lga->id),
            $this->state !== null => $query->where("{$table}.state_id", $this->state->id),
            default => $query,
        };
    }

    /**
     * As narrow(), but records disseminated to the user stay in (they come
     * from another place by definition).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function narrowUnlessShared(Builder $query, string $table, User $user): Builder
    {
        if ($this->lga === null && $this->state === null) {
            return $query;
        }

        $type = $table === 'threat_reports' ? 'threat_report' : 'flashpoint';

        return $query->where(fn (Builder $q) => $this->narrow($q, $table)
            ->orWhereIn("{$table}.id", RecordDissemination::query()->receivedBy($user)->active()->where('subject_type', $type)->select('subject_id')));
    }
}
