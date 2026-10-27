<?php

namespace App\Notifications;

use App\Models\State;
use App\Models\User;

/**
 * Weekly reminder to a State Director or Deputy whose state profile has not
 * been reviewed recently (BUFFALO_PROFILE_REVIEW_DAYS; Phase 11F).
 */
final class StateProfileReviewDue extends BuffaloNotification
{
    public function __construct(
        public readonly State $state,
        public readonly int $days,
    ) {}

    public function type(): string
    {
        return 'state_profile_review_due';
    }

    public function title(User $notifiable): string
    {
        return 'State profile review due';
    }

    public function body(User $notifiable): string
    {
        return "The profile of {$this->state->name} State has not been reviewed in the last {$this->days} days. Check it and mark it as reviewed.";
    }

    public function url(User $notifiable): string
    {
        return '/states/'.$this->state->ulid.'?tab=profile';
    }
}
