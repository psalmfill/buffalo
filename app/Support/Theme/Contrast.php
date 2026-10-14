<?php

namespace App\Support\Theme;

use InvalidArgumentException;

/**
 * WCAG 2.x contrast (Phase 11A.3): relative luminance of sRGB colours,
 * the contrast ratio between two, and the nearest colour (same hue and
 * saturation, lightness moved as little as possible) that reaches a ratio.
 */
final class Contrast
{
    /** Text: WCAG AA for normal text. */
    public const TEXT = 4.5;

    /** Chart elements, borders and other graphics: WCAG AA non-text. */
    public const GRAPHIC = 3.0;

    public static function ratio(string $foreground, string $background): float
    {
        $a = self::luminance($foreground);
        $b = self::luminance($background);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /** The ratio rounded down to two decimals (never overstating a pass). */
    public static function measured(string $foreground, string $background): float
    {
        return floor(self::ratio($foreground, $background) * 100) / 100;
    }

    public static function passes(string $foreground, string $background, float $minimum): bool
    {
        return self::ratio($foreground, $background) >= $minimum;
    }

    /**
     * The passing colour closest in lightness to $colour, or null when none
     * exists (cannot happen against a white or near-black background).
     */
    public static function suggest(string $colour, string $background, float $minimum): ?string
    {
        if (self::passes($colour, $background, $minimum)) {
            return self::normalise($colour);
        }

        [$h, $s, $l] = self::toHsl($colour);

        for ($step = 1; $step <= 100; $step++) {
            foreach ([$l - $step / 100, $l + $step / 100] as $candidate) {
                if ($candidate < 0 || $candidate > 1) {
                    continue;
                }

                $hex = self::fromHsl($h, $s, $candidate);

                if (self::passes($hex, $background, $minimum)) {
                    return $hex;
                }
            }
        }

        return null;
    }

    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }

    public static function normalise(string $hex): string
    {
        if (! self::isHex($hex)) {
            throw new InvalidArgumentException('Expected a colour as #rrggbb.');
        }

        return strtolower($hex);
    }

    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);
        $channel = function (int $value): float {
            $c = $value / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rgb(string $hex): array
    {
        $hex = substr(self::normalise($hex), 1);

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    /**
     * @return array{0: float, 1: float, 2: float} hue (0-360), saturation and lightness (0-1)
     */
    private static function toHsl(string $hex): array
    {
        [$r, $g, $b] = array_map(fn (int $c) => $c / 255, self::rgb($hex));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;

        if ($d == 0.0) {
            return [0.0, 0.0, $l];
        }

        $s = $d / (1 - abs(2 * $l - 1));
        $h = match ($max) {
            $r => 60 * fmod(($g - $b) / $d, 6),
            $g => 60 * (($b - $r) / $d + 2),
            default => 60 * (($r - $g) / $d + 4),
        };

        return [$h < 0 ? $h + 360 : $h, $s, $l];
    }

    private static function fromHsl(float $h, float $s, float $l): string
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0.0],
            $h < 120 => [$x, $c, 0.0],
            $h < 180 => [0.0, $c, $x],
            $h < 240 => [0.0, $x, $c],
            $h < 300 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };

        return sprintf('#%02x%02x%02x', (int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255));
    }
}
