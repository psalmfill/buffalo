<?php

namespace App\Support\Jurisdiction;

use App\Enums\JurisdictionLevel;
use App\Models\Lga;
use App\Models\State;

/**
 * Human-readable jurisdiction labels, one format everywhere:
 * "System", "National", "North East Zone, North West Zone", "Lagos State",
 * "Ikeja LGA, Lagos State", "Federal Capital Territory".
 */
final class JurisdictionLabel
{
    /**
     * @param  iterable<string>  $zoneNames  a zoned DDG's zones; empty means national
     */
    public static function for(JurisdictionLevel $level, ?State $state = null, ?Lga $lga = null, iterable $zoneNames = []): string
    {
        return match ($level) {
            JurisdictionLevel::System => 'System',
            JurisdictionLevel::National => self::national($zoneNames),
            JurisdictionLevel::State => self::state($state),
            JurisdictionLevel::Lga => ($lga->name ?? '?').' LGA, '.self::state($state),
        };
    }

    public static function state(?State $state): string
    {
        if ($state === null) {
            return '?';
        }

        // "Federal Capital Territory", not "Federal Capital Territory State".
        return str_ends_with($state->name, 'Territory') ? $state->name : $state->name.' State';
    }

    /**
     * @param  iterable<string>  $zoneNames
     */
    private static function national(iterable $zoneNames): string
    {
        $zones = collect($zoneNames)->sort()->map(fn (string $name): string => $name.' Zone');

        return $zones->isEmpty() ? 'National' : $zones->implode(', ');
    }
}
