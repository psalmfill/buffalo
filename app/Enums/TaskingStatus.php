<?php

namespace App\Enums;

/** A tasking's state (Phase 11H): draft → sent → recalled. */
enum TaskingStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Recalled = 'recalled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Recalled => 'Recalled',
        };
    }
}
