<?php

namespace App\Enums;

/**
 * Severity of an incident (and an incident type's default severity).
 */
enum Severity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
