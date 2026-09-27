<?php

namespace App\Actions\AccessRequests;

use App\Enums\AccessRequestStatus;
use App\Enums\GrantScope;
use App\Enums\RequestUrgency;
use App\Models\RecordAccessGrant;
use App\Models\RecordAccessRequest;
use App\Models\RecordMatchEvent;
use App\Models\User;
use App\Notifications\AccessRequestSubmitted;
use App\Support\Audit\AuditLog;
use App\Support\CrossJurisdiction\InvalidHitToken;
use App\Support\CrossJurisdiction\PersonMatchService;
use App\Support\Jurisdiction\AccessRequestApprovers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Requests temporary access to a record in another jurisdiction (Phase 3C).
 * The record is resolved server-side from a valid hit token (bound to the
 * user, short-lived): the client never names a record. One open request
 * per requester and record, and none while a grant is active.
 */
final class RequestAccess
{
    public function __construct(
        private readonly PersonMatchService $matches,
        private readonly AccessRequestApprovers $approvers,
    ) {}

    /**
     * @param  array{reason: string, reference?: string|null, scope: string, urgency: string, requested_days: int}  $data
     *
     * @throws ValidationException
     */
    public function __invoke(User $requester, string $token, array $data): RecordAccessRequest
    {
        try {
            $hit = $this->matches->resolveHit($token, $requester);
        } catch (InvalidHitToken $e) {
            throw ValidationException::withMessages(['token' => $e->getMessage()]);
        }

        $person = $hit['person'];

        $event = RecordMatchEvent::query()->find($hit['match_event_id']);

        if ($event === null || $event->user_id !== $requester->id || $event->subject_id !== $person->id || $event->suppressed) {
            throw ValidationException::withMessages(['token' => 'The hit is not valid.']);
        }

        $open = RecordAccessRequest::query()
            ->where('requester_id', $requester->id)
            ->where('subject_type', $person->getMorphClass())
            ->where('subject_id', $person->id)
            ->whereIn('status', AccessRequestStatus::openValues())
            ->exists();

        if ($open) {
            throw ValidationException::withMessages(['token' => 'You already have an open request for this record.']);
        }

        $granted = RecordAccessGrant::query()->active()
            ->where('user_id', $requester->id)
            ->where('subject_type', $person->getMorphClass())
            ->where('subject_id', $person->id)
            ->exists();

        if ($granted) {
            throw ValidationException::withMessages(['token' => 'You already have access to this record.']);
        }

        $request = DB::transaction(function () use ($requester, $person, $event, $data): RecordAccessRequest {
            $request = RecordAccessRequest::query()->create([
                'requester_id' => $requester->id,
                'requester_state_id' => $requester->state_id,
                'requester_lga_id' => $requester->lga_id,
                'subject_type' => $person->getMorphClass(),
                'subject_id' => $person->id,
                'owning_state_id' => $person->state_id,
                'owning_lga_id' => $person->lga_id,
                'match_event_id' => $event->id,
                'reason' => trim($data['reason']),
                'reference' => $data['reference'] ?? null,
                'scope' => GrantScope::from($data['scope']),
                'urgency' => RequestUrgency::from($data['urgency']),
                'requested_days' => (int) $data['requested_days'],
                'status' => AccessRequestStatus::Pending,
            ]);

            AuditLog::event('access_request_created', 'Access request created', $request, [
                'scope' => $request->scope->value,
                'urgency' => $request->urgency->value,
                'requested_days' => $request->requested_days,
            ], $requester);

            return $request;
        });

        $requester->loadMissing(['state', 'lga']);
        $place = $requester->lga !== null ? "{$requester->lga->name} LGA, {$requester->state?->name} State" : ($requester->state?->name.' State');

        Notification::send($this->approvers->deciders($request), new AccessRequestSubmitted(
            $request->ulid,
            $requester->name,
            $place,
            $request->urgency === RequestUrgency::Urgent,
        ));

        return $request;
    }
}
