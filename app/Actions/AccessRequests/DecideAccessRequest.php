<?php

namespace App\Actions\AccessRequests;

use App\Enums\AccessRequestStatus;
use App\Enums\GrantScope;
use App\Models\RecordAccessGrant;
use App\Models\RecordAccessRequest;
use App\Models\User;
use App\Notifications\AccessRequestDecided;
use App\Support\Audit\AuditLog;
use App\Support\Jurisdiction\AccessRequestParties;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Approves (creating the grant in the same transaction) or rejects an
 * access request (Phase 3C). The approver may shorten the duration or
 * narrow full to profile, never widen. Who may decide is checked by the
 * policy (AccessRequestApprovers).
 */
final class DecideAccessRequest
{
    public function approve(RecordAccessRequest $request, User $decider, int $days, GrantScope $scope, ?string $note): RecordAccessGrant
    {
        if ($days < 1 || $days > $request->requested_days) {
            throw new InvalidArgumentException('The duration may only be shortened.');
        }

        if (! $request->scope->includes($scope)) {
            throw new InvalidArgumentException('The scope may only be narrowed.');
        }

        $grant = DB::transaction(function () use ($request, $decider, $days, $scope, $note): RecordAccessGrant {
            $this->lockOpen($request);
            $now = CarbonImmutable::now();

            $request->forceFill([
                'status' => AccessRequestStatus::Approved,
                'decided_by' => $decider->id,
                'decided_at' => $now,
                'decision_note' => $note,
                'granted_days' => $days,
                'granted_scope' => $scope,
            ])->save();

            $grant = RecordAccessGrant::query()->create([
                'request_id' => $request->id,
                'user_id' => $request->requester_id,
                'subject_type' => $request->subject_type,
                'subject_id' => $request->subject_id,
                'scope' => $scope,
                'granted_by' => $decider->id,
                'starts_at' => $now,
                'expires_at' => $now->addDays($days),
            ]);

            AuditLog::event('access_request_approved', "Access request approved: {$scope->label()}, {$days} days", $request, [
                'granted_scope' => $scope->value,
                'granted_days' => $days,
                'narrowed' => $scope !== $request->scope,
                'shortened' => $days < $request->requested_days,
            ], $decider);

            return $grant;
        });

        $this->notify($request, true);

        return $grant;
    }

    public function reject(RecordAccessRequest $request, User $decider, string $note): void
    {
        if (trim($note) === '') {
            throw new InvalidArgumentException('A note is required to reject a request.');
        }

        DB::transaction(function () use ($request, $decider, $note): void {
            $this->lockOpen($request);

            $request->forceFill([
                'status' => AccessRequestStatus::Rejected,
                'decided_by' => $decider->id,
                'decided_at' => now(),
                'decision_note' => trim($note),
            ])->save();

            AuditLog::event('access_request_rejected', 'Access request rejected', $request, ['note' => trim($note)], $decider);
        });

        $this->notify($request, false);
    }

    /** Refuses to decide a request that is no longer open (concurrent decisions). */
    private function lockOpen(RecordAccessRequest $request): void
    {
        $status = RecordAccessRequest::query()->whereKey($request->id)->lockForUpdate()->toBase()->value('status');

        if (! in_array($status, AccessRequestStatus::openValues(), true)) {
            throw new InvalidArgumentException('The request is no longer open.');
        }
    }

    private function notify(RecordAccessRequest $request, bool $approved): void
    {
        AccessRequestParties::attach($request);
        $request->requester->notify(new AccessRequestDecided($request->ulid, $approved, $request->owningState->name.' State'));
    }
}
