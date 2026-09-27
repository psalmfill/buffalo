<?php

namespace App\Actions\AccessRequests;

use App\Models\ApprovalDelegation;
use App\Models\User;
use App\Notifications\DelegationChanged;
use App\Support\Audit\AuditLog;
use App\Support\Jurisdiction\AccessRequestApprovers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Approval delegations (Phase 3C): a State Director lets a Deputy State
 * Director of the same state decide access requests. Created, revoked, or
 * ended automatically when either party's officer posting closes; each is
 * a named audit event and both parties are told. Who may create is checked
 * by the request (DelegationRequest).
 */
final class ManageDelegations
{
    public function __construct(private readonly AccessRequestApprovers $approvers) {}

    public function create(User $director, User $deputy, CarbonImmutable $startsAt, ?CarbonImmutable $endsAt, ?string $reason): ApprovalDelegation
    {
        $delegation = DB::transaction(function () use ($director, $deputy, $startsAt, $endsAt, $reason): ApprovalDelegation {
            $delegation = new ApprovalDelegation(['starts_at' => $startsAt, 'ends_at' => $endsAt, 'reason' => $reason]);
            $delegation->forceFill([
                'state_id' => $director->state_id,
                'delegator_id' => $director->id,
                'delegate_id' => $deputy->id,
                'created_by' => $director->id,
            ])->save();

            AuditLog::event('delegation_created', "Approval delegated to {$deputy->name}", $delegation, [
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $endsAt?->toIso8601String(),
            ], $director);

            return $delegation;
        });

        $this->tell($delegation, 'created');

        return $delegation;
    }

    public function revoke(ApprovalDelegation $delegation, User $actor): void
    {
        DB::transaction(function () use ($delegation, $actor): void {
            $delegation->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->id, 'end_reason' => 'revoked'])->save();
            AuditLog::event('delegation_revoked', 'Approval delegation revoked', $delegation, [], $actor);
        });

        $this->tell($delegation, 'revoked');
    }

    /**
     * Ends every open delegation where the user is either party: called
     * when the user's officer posting closes (role or jurisdiction change,
     * deactivation).
     */
    public function endFor(User $user): void
    {
        foreach ($this->approvers->openDelegationsInvolving($user) as $delegation) {
            $delegation->forceFill(['revoked_at' => now(), 'end_reason' => 'posting_closed'])->save();
            AuditLog::event('delegation_ended', 'Approval delegation ended: a posting changed', $delegation, ['user' => $user->ulid]);
            $this->tell($delegation, 'ended');
        }
    }

    private function tell(ApprovalDelegation $delegation, string $change): void
    {
        [$delegator, $delegate] = $this->approvers->delegationParties($delegation);

        if ($delegator === null || $delegate === null) {
            return;
        }

        Notification::send([$delegator, $delegate], new DelegationChanged($change, $delegator->name, $delegate->name));
    }
}
