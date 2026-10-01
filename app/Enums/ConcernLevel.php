<?php

namespace App\Enums;

/**
 * Concern level of a flashpoint or blackspot (Phase 6A), lowest to highest.
 * Sets the review interval (config buffalo.flashpoints.review_days).
 */
enum ConcernLevel: string
{
    case Low = 'low';
    case Moderate = 'moderate';
    case High = 'high';
    case Severe = 'severe';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Moderate => 'Moderate',
            self::High => 'High',
            self::Severe => 'Severe',
        };
    }

    /** Higher is more serious (for sorting). */
    public function rank(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Moderate => 2,
            self::High => 3,
            self::Severe => 4,
        };
    }

    /** Days from an assessment to the next review. */
    public function reviewDays(): int
    {
        return (int) config("buffalo.flashpoints.review_days.{$this->value}");
    }
}
