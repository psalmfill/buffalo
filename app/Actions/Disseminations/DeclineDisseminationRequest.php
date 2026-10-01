<?php

namespace App\Actions\Disseminations;

use App\Enums\DisseminationRequestStatus;
use App\Enums\NotificationSeverity;
use App\Models\DisseminationRequest;
use App\Models\User;
use App\Support\Disseminations\DisseminationNotifier;
use App\Support\Jurisdiction\Contracts\Disseminable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The State Director or Deputy declines a dissemination request with a
 * note (Phase 7A); the requester is told. (Actioning happens by
 * disseminating: DisseminateRecord marks the request actioned.)
 */
final class DeclineDisseminationRequest
{
    public function __construct(private readonly DisseminationNotifier $notifier) {}

    public function __invoke(DisseminationRequest $request, Model&Disseminable $subject, string $note, User $actor): void
    {
        if ($request->status !== DisseminationRequestStatus::Pending) {
            throw ValidationException::withMessages(['note' => 'This request has already been decided.']);
        }

        $request->forceFill([
            'status' => DisseminationRequestStatus::Declined,
            'decided_by' => $actor->id,
            'decided_at' => CarbonImmutable::now(),
            'decision_note' => trim($note),
        ])->save();

        $this->notifier->send($this->notifier->users($request->requested_by), $subject, 'declined', NotificationSeverity::Info);
    }
}
