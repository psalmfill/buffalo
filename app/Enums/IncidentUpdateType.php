<?php

namespace App\Enums;

/**
 * Kinds of incident follow-up (Phase 5C).
 */
enum IncidentUpdateType: string
{
    case FollowUp = 'follow_up';
    case ActionTaken = 'action_taken';
    case Intelligence = 'intelligence';
    case StatusNote = 'status_note';

    public function label(): string
    {
        return match ($this) {
            self::FollowUp => 'Follow-up',
            self::ActionTaken => 'Action taken',
            self::Intelligence => 'Intelligence',
            self::StatusNote => 'Status note',
        };
    }
}
