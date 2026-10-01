<?php

namespace App\Actions\Disseminations;

use App\Enums\DisseminationRequestStatus;
use App\Enums\NotificationSeverity;
use App\Models\DisseminationRequest;
use App\Models\User;
use App\Support\Disseminations\DisseminationNotifier;
use App\Support\Jurisdiction\Contracts\Disseminable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * An LGA chief (or the record's creator) asks the State Director or Deputy
 * of the owning state to disseminate a record (Phase 7A), with suggested
 * targets and a reason. One pending request per record and requester.
 * They are notified (warning).
 */
final class RequestDissemination
{
    public function __construct(private readonly DisseminationNotifier $notifier) {}

    /**
     * @param  list<array{level: string, ulid: string}>  $targets
     */
    public function __invoke(Model&Disseminable $subject, array $targets, string $reason, User $actor): DisseminationRequest
    {
        $pending = DisseminationRequest::query()
            ->where('subject_type', $subject->disseminationType())
            ->where('subject_id', $subject->getKey())
            ->where('requested_by', $actor->id)
            ->where('status', DisseminationRequestStatus::Pending->value)
            ->exists();

        if ($pending) {
            throw ValidationException::withMessages(['reason' => 'You already have a pending request for this record.']);
        }

        $request = new DisseminationRequest;
        $request->forceFill([
            'subject_type' => $subject->disseminationType(),
            'subject_id' => $subject->getKey(),
            'state_id' => $subject->getAttribute('state_id'),
            'lga_id' => $subject->getAttribute('lga_id'),
            'requested_by' => $actor->id,
            'suggested_targets' => $targets,
            'reason' => trim($reason),
        ])->save();

        $this->notifier->send($this->notifier->owners($subject), $subject, 'requested', NotificationSeverity::Warning, except: [$actor->id]);

        return $request;
    }
}
