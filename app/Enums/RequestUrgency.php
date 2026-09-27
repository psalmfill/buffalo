<?php

namespace App\Enums;

enum RequestUrgency: string
{
    case Normal = 'normal';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Urgent => 'Urgent',
        };
    }
}
