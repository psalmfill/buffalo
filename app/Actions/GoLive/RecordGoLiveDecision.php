<?php

namespace App\Actions\GoLive;

use App\Enums\GoLiveDecision;
use App\Models\GoLiveDecisionRecord;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\GoLive\DataStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a client decision for go-live (Phase 9B.6). Append-only: a new
 * record supersedes the previous one. A reference data sign-off records
 * the reference data version it applies to.
 */
final class RecordGoLiveDecision
{
    public function __construct(private readonly DataStatus $data) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(GoLiveDecision $decision, string $outcome, string $details, string $representative, CarbonImmutable $decidedOn, User $actor): GoLiveDecisionRecord
    {
        if (! array_key_exists($outcome, $decision->outcomes())) {
            throw ValidationException::withMessages(['outcome' => 'Choose one of the outcomes listed for this decision.']);
        }

        if ($decidedOn->isAfter(now()->endOfDay())) {
            throw ValidationException::withMessages(['decided_on' => 'The decision date cannot be in the future.']);
        }

        return DB::transaction(function () use ($decision, $outcome, $details, $representative, $decidedOn, $actor): GoLiveDecisionRecord {
            $record = new GoLiveDecisionRecord([
                'decision' => $decision,
                'outcome' => $outcome,
                'details' => trim($details),
                'client_representative' => trim($representative),
                'decided_on' => $decidedOn,
                'reference_version' => $decision === GoLiveDecision::ReferenceData ? $this->data->referenceVersion() : null,
            ]);
            $record->forceFill(['recorded_by' => $actor->id])->save();

            AuditLog::event('golive_decision_recorded', "Go-live decision recorded: {$decision->label()}", $record, [
                'decision' => $decision->value,
                'outcome' => $outcome,
                'client_representative' => $record->client_representative,
                'decided_on' => $decidedOn->toDateString(),
            ], $actor);

            return $record;
        });
    }
}
