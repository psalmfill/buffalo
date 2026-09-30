<?php

namespace App\Enums;

/**
 * How precisely an incident's time is known (Phase 5A).
 */
enum OccurrencePrecision: string
{
    case Exact = 'exact';
    case Approximate = 'approximate';
    case DateOnly = 'date_only';

    public function label(): string
    {
        return match ($this) {
            self::Exact => 'Exact',
            self::Approximate => 'Approximate',
            self::DateOnly => 'Date only',
        };
    }
}
