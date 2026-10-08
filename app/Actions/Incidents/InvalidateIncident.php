<?php

namespace App\Actions\Incidents;

use App\Enums\IncidentStatus;
use App\Enums\InvalidReason;
use App\Events\Incidents\IncidentUpdated;
use App\Models\Incident;
use App\Models\IncidentConsolidation;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Incidents\Consolidation;
use App\Support\Incidents\IncidentNotifier;
use App\Support\Incidents\IncidentWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Marks a submitted or verified incident invalid (Phase 5C): a reason, a
 * note for "other", the original for a duplicate. Invalid incidents are
 * locked and left out of statistics; the reporting officer and the creator
 * are told.
 *
 * Phase 10B: a duplicate may first be CONSOLIDATED into the original, in
 * the same transaction: both incidents are locked (ID order), the content
 * is moved, copied or added (Consolidation), an incident_consolidations
 * row records it, and then the duplicate is invalidated (which locks it).
 * If anything fails, nothing changes. Consolidation needs invalidate
 * rights on the duplicate and update (or amend, with a reason) rights on
 * the original, which must not be closed or invalid.
 */
final class InvalidateIncident
{
    public function __construct(
        private readonly IncidentWorkflow $workflow,
        private readonly IncidentNotifier $notifier,
        private readonly Consolidation $consolidation,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(Incident $incident, User $actor, InvalidReason $reason, ?string $note, ?Incident $duplicateOf, bool $consolidate = false, ?string $amendmentReason = null): Incident
    {
        $consolidated = null;

        $moved = DB::transaction(function () use ($incident, $actor, $reason, $note, $duplicateOf, $consolidate, $amendmentReason, &$consolidated): Incident {
            if ($consolidate && $reason === InvalidReason::Duplicate && $duplicateOf !== null) {
                $consolidated = $this->consolidate($incident, $duplicateOf, $actor, $amendmentReason);
            }

            return $this->workflow->move($incident, IncidentStatus::Invalid, $actor, [
                'invalidated_by' => $actor->id,
                'invalidated_at' => now(),
                'invalid_reason' => $reason,
                'invalid_note' => $note,
                'duplicate_of_incident_id' => $duplicateOf?->id,
            ], $reason->label().($note !== null ? ": {$note}" : ''), 'invalidated', 'Incident marked invalid (locked)', [
                'reason' => $reason->value,
                ...($duplicateOf !== null ? ['duplicate_of' => $duplicateOf->ulid, 'duplicate_of_reference' => $duplicateOf->reference_no] : []),
                ...($consolidated !== null ? ['consolidation' => $consolidated->ulid] : []),
            ]);
        });

        $this->notifier->send($moved, 'invalidated', $this->notifier->officers($moved));

        if ($consolidated !== null && $duplicateOf !== null) {
            $this->notifier->send($duplicateOf, 'consolidated', $this->notifier->users([$duplicateOf->reporting_officer_id]), except: [$actor->id]);
        }

        return $moved;
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function consolidate(Incident $duplicate, Incident $original, User $actor, ?string $amendmentReason): IncidentConsolidation
    {
        // Both incidents, in ID order, so two consolidations cannot interleave.
        $locked = Incident::query()->whereKey([$duplicate->id, $original->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $duplicate = $locked->get($duplicate->id) ?? throw new AuthorizationException;
        $original = $locked->get($original->id) ?? throw new AuthorizationException;

        if (! $actor->can('invalidate', $duplicate) || ! $actor->can('update', $original)) {
            throw new AuthorizationException('You may not consolidate into this incident.');
        }

        if ($original->isLocked()) {
            throw ValidationException::withMessages(['duplicate_of' => 'The original incident is closed or invalid: it cannot receive content.']);
        }

        $amendmentReason = $amendmentReason === null ? '' : trim($amendmentReason);
        if ($original->requiresAmendmentReason() && mb_strlen($amendmentReason) < 10) {
            throw ValidationException::withMessages(['amendment_reason' => 'The original incident is verified: give an amendment reason (at least 10 characters).']);
        }

        $manifest = $this->consolidation->apply($this->consolidation->plan($duplicate, $original, $actor), $duplicate, $original, $actor);

        $record = new IncidentConsolidation;
        $record->forceFill([
            'ulid' => strtolower((string) Str::ulid()),
            'original_incident_id' => $original->id,
            'duplicate_incident_id' => $duplicate->id,
            'state_id' => $original->state_id,
            'lga_id' => $original->lga_id,
            'manifest' => ['version' => 1, 'items' => $manifest],
            'consolidated_by' => $actor->id,
            'consolidated_at' => now(),
        ])->save();

        $counts = array_count_values(array_column($manifest, 'outcome'));
        AuditLog::event('consolidated_from', "Content consolidated from {$duplicate->reference_no}", $original, ['from' => $duplicate->reference_no, 'consolidation' => $record->ulid, 'outcomes' => $counts], $actor);
        AuditLog::event('consolidated_into', "Content consolidated into {$original->reference_no}", $duplicate, ['into' => $original->reference_no, 'consolidation' => $record->ulid, 'outcomes' => $counts], $actor);

        // Clients refetch the original through their own scopes.
        IncidentUpdated::dispatch($original, $this->workflow->stateUlid($original));

        if ($original->requiresAmendmentReason()) {
            AuditLog::event('amended', 'Incident amended after verification', $original, ['reason' => $amendmentReason], $actor);
        }

        return $record;
    }
}
