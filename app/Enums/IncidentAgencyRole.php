<?php

namespace App\Enums;

/**
 * An agency's role in responding to an incident (Phase 5A).
 */
enum IncidentAgencyRole: string
{
    case FirstResponder = 'first_responder';
    case Lead = 'lead';
    case Support = 'support';
    case Notified = 'notified';

    public function label(): string
    {
        return match ($this) {
            self::FirstResponder => 'First responder',
            self::Lead => 'Lead agency',
            self::Support => 'Support',
            self::Notified => 'Notified',
        };
    }
}
