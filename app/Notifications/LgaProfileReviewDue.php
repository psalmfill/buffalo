<?php

namespace App\Notifications;

use App\Models\Lga;
use App\Models\User;

/**
 * Weekly reminder to an LGA chief whose LGA profile has not been reviewed
 * recently (BUFFALO_PROFILE_REVIEW_DAYS).
 */
final class LgaProfileReviewDue extends BuffaloNotification
{
    public function __construct(
        public readonly Lga $lga,
        public readonly int $days,
    ) {}

    public function type(): string
    {
        return 'lga_profile_review_due';
    }

    public function title(User $notifiable): string
    {
        return 'LGA profile review due';
    }

    public function body(User $notifiable): string
    {
        return "The profile of {$this->lga->name} LGA has not been reviewed in the last {$this->days} days. Check it and mark it as reviewed.";
    }

    public function url(User $notifiable): string
    {
        return '/lgas/'.$this->lga->ulid.'?tab=profile';
    }
}
