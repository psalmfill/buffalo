<?php

namespace App\Enums;

/**
 * Why an incident was marked invalid (Phase 5C).
 */
enum InvalidReason: string
{
    case Duplicate = 'duplicate';
    case FalseReport = 'false_report';
    case NotSecurityRelated = 'not_security_related';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Duplicate => 'Duplicate of another incident',
            self::FalseReport => 'False report',
            self::NotSecurityRelated => 'Not security related',
            self::Other => 'Other',
        };
    }
}
