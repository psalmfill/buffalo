<?php

namespace App\Enums;

enum CommunityType: string
{
    case Village = 'village';
    case Town = 'town';
    case Settlement = 'settlement';
    case Camp = 'camp';
    case Estate = 'estate';
    case Waterside = 'waterside';
    case Other = 'other';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
