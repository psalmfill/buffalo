<?php

namespace App\Enums;

/**
 * Who a tasking went to (Phase 11H), derived on send from the ticked LGAs,
 * never chosen: one LGA, some, or every active LGA of the state.
 */
enum TaskingTargetMode: string
{
    case Single = 'single';
    case Selected = 'selected';
    case AllLgas = 'all_lgas';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'One LGA',
            self::Selected => 'Selected LGAs',
            self::AllLgas => 'All LGAs',
        };
    }

    public static function derive(int $chosen, int $activeInState): self
    {
        return match (true) {
            $chosen >= $activeInState && $activeInState > 1 => self::AllLgas,
            $chosen === 1 => self::Single,
            default => self::Selected,
        };
    }
}
