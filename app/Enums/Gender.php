<?php

namespace App\Enums;

/**
 * Gender of a person record (Phase 3A).
 */
enum Gender: string
{
    case Male = 'male';
    case Female = 'female';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Male => 'Male',
            self::Female => 'Female',
            self::Unknown => 'Unknown',
        };
    }
}
