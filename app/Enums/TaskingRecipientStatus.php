<?php

namespace App\Enums;

/**
 * One LGA's progress on a tasking (Phase 11H). Only moves forward
 * (database trigger).
 */
enum TaskingRecipientStatus: string
{
    case Delivered = 'delivered';
    case Read = 'read';
    case Acknowledged = 'acknowledged';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Delivered => 'Not read',
            self::Read => 'Read',
            self::Acknowledged => 'Acknowledged',
            self::Completed => 'Completed',
        };
    }

    public function rank(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }
}
