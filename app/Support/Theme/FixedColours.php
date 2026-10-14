<?php

namespace App\Support\Theme;

/**
 * Colours that carry a fixed meaning across the system and in printed
 * reports (Phase 11A.3), shown read-only on the Theme page and never
 * changed by a theme. They mirror the frontend constants
 * (SEVERITY_COLOURS in incident-table.tsx, URGENCY_COLOURS in
 * threat-display.tsx, the classification banner in system-banner.tsx);
 * ThemeTest keeps them in step.
 */
final class FixedColours
{
    public const SEVERITY = ['low' => '#2563eb', 'medium' => '#ca8a04', 'high' => '#ea580c', 'critical' => '#b91c1c'];

    public const CLASSIFICATION = ['restricted' => '#fcd34d', 'confidential' => '#1d4ed8', 'secret' => '#b91c1c'];

    public const URGENCY = ['routine' => '#2563eb', 'priority' => '#ea580c', 'immediate' => '#b91c1c'];

    /**
     * @return list<array{group: string, colours: list<array{name: string, colour: string}>}>
     */
    public static function present(): array
    {
        $group = fn (string $label, array $colours) => [
            'group' => $label,
            'colours' => array_map(fn (string $name, string $colour) => ['name' => ucfirst($name), 'colour' => $colour], array_keys($colours), $colours),
        ];

        return [
            $group('Severity', self::SEVERITY),
            $group('Classification banners', self::CLASSIFICATION),
            $group('Threat urgency', self::URGENCY),
        ];
    }
}
