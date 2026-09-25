<?php

namespace App\Enums;

/**
 * Kind of security agency. Only state outfits belong to a state.
 */
enum AgencyType: string
{
    case Federal = 'federal';
    case State = 'state';
    case Community = 'community';
    case Private = 'private';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
