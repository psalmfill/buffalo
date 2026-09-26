<?php

namespace App\Enums;

/**
 * Classification of an intelligence record (CLAUDE.md section 4a), lowest
 * first. Classification narrows access within a user's jurisdiction; it
 * never widens it.
 */
enum Classification: string
{
    case Restricted = 'restricted';
    case Confidential = 'confidential';
    case Secret = 'secret';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** 1 (restricted) to 3 (secret). */
    public function rank(): int
    {
        return match ($this) {
            self::Restricted => 1,
            self::Confidential => 2,
            self::Secret => 3,
        };
    }

    public function isAtMost(self $level): bool
    {
        return $this->rank() <= $level->rank();
    }

    /**
     * Every level up to and including $max, lowest first.
     *
     * @return list<self>
     */
    public static function upTo(self $max): array
    {
        return array_values(array_filter(self::cases(), fn (self $level): bool => $level->isAtMost($max)));
    }
}
