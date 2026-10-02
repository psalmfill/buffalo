<?php

namespace App\Services\Reports;

use App\Enums\Classification;

/**
 * The highest classification among the records a report includes (Phase
 * 7D), minimum `restricted`: the run's marking.
 */
final class LevelTracker
{
    private Classification $level = Classification::Restricted;

    public function see(Classification|string|null $level): void
    {
        $level = is_string($level) ? Classification::tryFrom($level) : $level;

        if ($level !== null && ! $level->isAtMost($this->level)) {
            $this->level = $level;
        }
    }

    /**
     * @param  iterable<Classification|string|null>  $levels
     */
    public function seeAll(iterable $levels): void
    {
        foreach ($levels as $level) {
            $this->see($level);
        }
    }

    public function level(): Classification
    {
        return $this->level;
    }
}
