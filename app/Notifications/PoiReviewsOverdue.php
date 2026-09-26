<?php

namespace App\Notifications;

use App\Models\Lga;
use App\Models\State;
use App\Models\User;

/**
 * Daily: persons of interest in an LGA (for its chief) or a state (for its
 * State Director) are past their review date. Counts only: notification
 * payloads never contain names of persons of interest.
 */
final class PoiReviewsOverdue extends BuffaloNotification
{
    public function __construct(
        public readonly Lga|State $place,
        public readonly int $count,
    ) {}

    public function type(): string
    {
        return 'poi_reviews_overdue';
    }

    public function title(User $notifiable): string
    {
        return 'Person of interest reviews overdue';
    }

    public function body(User $notifiable): string
    {
        $where = $this->place instanceof Lga ? "{$this->place->name} LGA" : "{$this->place->name} State";
        $records = $this->count === 1 ? '1 person of interest is' : "{$this->count} persons of interest are";

        return "{$records} in {$where} past the review date. Review each record and mark it reviewed.";
    }

    public function url(User $notifiable): string
    {
        return $this->place instanceof Lga
            ? '/persons-of-interest?overdue=1&lga='.$this->place->ulid
            : '/persons-of-interest?overdue=1';
    }
}
