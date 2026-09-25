<?php

namespace App\Enums;

/**
 * Terrain types an LGA profile may list.
 */
enum Terrain: string
{
    case Urban = 'urban';
    case Rural = 'rural';
    case Riverine = 'riverine';
    case Coastal = 'coastal';
    case Forest = 'forest';
    case Savannah = 'savannah';
    case Hilly = 'hilly';
    case DesertFringe = 'desert_fringe';

    public function label(): string
    {
        return match ($this) {
            self::DesertFringe => 'Desert fringe',
            default => ucfirst($this->value),
        };
    }
}
