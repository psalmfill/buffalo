<?php

namespace App\Support\Theme;

use App\Models\AppSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Configurable colours (Phase 11A.3): brand primary and accent, the chart
 * palette and the KPI tile colours, each for light and dark mode. Stored
 * as one JSON document (the `ui_theme` setting), cached, and applied as
 * CSS custom properties (see css()).
 *
 * Severity, classification and urgency colours are NOT here: they carry
 * fixed meanings across the system and in printed reports (FixedColours).
 *
 * Every colour is checked against the card background of its mode: text
 * colours (brand primary, KPI tiles) at 4.5:1, graphics (accent, chart
 * palette) at 3:1 (WCAG AA).
 */
final class Theme
{
    public const KEY = 'ui_theme';

    private const CACHE = 'buffalo:ui_theme';

    public const MODES = ['light', 'dark'];

    /** The card background each mode's colours are drawn on (app.css --card). */
    public const BACKGROUNDS = ['light' => '#ffffff', 'dark' => '#0a0a0a'];

    public const KPI_TYPES = ['incidents', 'casualties', 'threats', 'verification', 'coverage'];

    public const PALETTE_SIZE = 8;

    /**
     * @return array{brand: array{primary: array{light: string, dark: string}, accent: array{light: string, dark: string}}, chart: array{light: list<string>, dark: list<string>}, kpi: array<string, array{light: string, dark: string}>}
     */
    public static function defaults(): array
    {
        return [
            'brand' => [
                'primary' => ['light' => '#171717', 'dark' => '#fafafa'],
                'accent' => ['light' => '#2563eb', 'dark' => '#60a5fa'],
            ],
            'chart' => [
                'light' => ['#2563eb', '#dc2626', '#16a34a', '#d97706', '#7c3aed', '#0891b2', '#db2777', '#4b5563'],
                'dark' => ['#60a5fa', '#f87171', '#4ade80', '#fbbf24', '#a78bfa', '#22d3ee', '#f472b6', '#9ca3af'],
            ],
            'kpi' => [
                'incidents' => ['light' => '#1d4ed8', 'dark' => '#93c5fd'],
                'casualties' => ['light' => '#b91c1c', 'dark' => '#fca5a5'],
                'threats' => ['light' => '#c2410c', 'dark' => '#fdba74'],
                'verification' => ['light' => '#15803d', 'dark' => '#86efac'],
                'coverage' => ['light' => '#6d28d9', 'dark' => '#c4b5fd'],
            ],
        ];
    }

    /**
     * Every configurable colour: dotted path => [label, minimum ratio].
     *
     * @return array<string, array{label: string, minimum: float}>
     */
    public static function fields(): array
    {
        $fields = [];

        foreach (self::MODES as $mode) {
            $fields["brand.primary.{$mode}"] = ['label' => "Primary ({$mode})", 'minimum' => Contrast::TEXT];
            $fields["brand.accent.{$mode}"] = ['label' => "Accent ({$mode})", 'minimum' => Contrast::GRAPHIC];

            for ($i = 0; $i < self::PALETTE_SIZE; $i++) {
                $fields["chart.{$mode}.{$i}"] = ['label' => 'Chart colour '.($i + 1)." ({$mode})", 'minimum' => Contrast::GRAPHIC];
            }

            foreach (self::KPI_TYPES as $type) {
                $fields["kpi.{$type}.{$mode}"] = ['label' => ucfirst($type)." tile ({$mode})", 'minimum' => Contrast::TEXT];
            }
        }

        return $fields;
    }

    public static function modeOf(string $path): string
    {
        return str_contains($path, '.dark') ? 'dark' : 'light';
    }

    /**
     * Contrast failures of a complete document: path => message.
     *
     * @param  array<string, mixed>  $theme
     * @return array<string, string>
     */
    public static function contrastErrors(array $theme): array
    {
        $errors = [];

        foreach (self::fields() as $path => $field) {
            $colour = Arr::get($theme, $path);

            if (! Contrast::isHex($colour)) {
                continue;
            }

            $mode = self::modeOf($path);
            $background = self::BACKGROUNDS[$mode];

            if (! Contrast::passes($colour, $background, $field['minimum'])) {
                $suggestion = Contrast::suggest($colour, $background, $field['minimum']);
                $errors[$path] = sprintf(
                    '%s: contrast %.2f:1 against the %s card background; at least %s:1 is needed.%s',
                    $field['label'],
                    Contrast::measured($colour, $background),
                    $mode,
                    rtrim(rtrim(number_format($field['minimum'], 1), '0'), '.'),
                    $suggestion === null ? '' : " Nearest passing colour: {$suggestion}.",
                );
            }
        }

        return $errors;
    }

    /**
     * The current theme: the stored document over the defaults (a stored
     * document that no longer validates is ignored).
     *
     * @return array{brand: array{primary: array{light: string, dark: string}, accent: array{light: string, dark: string}}, chart: array{light: list<string>, dark: list<string>}, kpi: array<string, array{light: string, dark: string}>}
     */
    public static function current(): array
    {
        /** @var array<string, mixed>|null $stored */
        $stored = Cache::rememberForever(self::CACHE, fn (): ?array => AppSetting::value(self::KEY));

        return is_array($stored) && self::isComplete($stored) && self::contrastErrors($stored) === []
            ? self::normalise($stored)
            : self::defaults();
    }

    public static function isCustomised(): bool
    {
        return self::current() !== self::defaults();
    }

    /** After a change (SaveTheme, ResetTheme). */
    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE);
    }

    /**
     * @return list<string>
     */
    public static function chartPalette(string $mode = 'light'): array
    {
        return self::current()['chart'][$mode === 'dark' ? 'dark' : 'light'];
    }

    /**
     * CSS custom properties for both modes. Every value is a validated
     * #rrggbb colour (never free text), so the output cannot carry CSS
     * injection. The selectors outrank app.css's :root and .dark.
     */
    public static function css(): string
    {
        $theme = self::current();
        $blocks = [];

        foreach (['light' => ':root:root', 'dark' => 'html.dark'] as $mode => $selector) {
            $vars = [
                '--primary' => $theme['brand']['primary'][$mode],
                // The card background reads on the primary (both pass 4.5:1).
                '--primary-foreground' => self::BACKGROUNDS[$mode],
                '--brand-accent' => $theme['brand']['accent'][$mode],
            ];

            foreach ($theme['chart'][$mode] as $i => $colour) {
                $vars['--chart-palette-'.($i + 1)] = $colour;
            }

            foreach (self::KPI_TYPES as $type) {
                $vars["--kpi-{$type}"] = $theme['kpi'][$type][$mode];
            }

            $body = implode('', array_map(fn (string $name, string $value) => "{$name}:".Contrast::normalise($value).';', array_keys($vars), $vars));
            $blocks[] = "{$selector}{{$body}}";
        }

        return implode("\n", $blocks);
    }

    /**
     * @param  array<string, mixed>  $theme
     */
    private static function isComplete(array $theme): bool
    {
        foreach (array_keys(self::fields()) as $path) {
            if (! Contrast::isHex(Arr::get($theme, $path))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Only the known fields, lower-cased.
     *
     * @param  array<string, mixed>  $theme
     * @return array{brand: array{primary: array{light: string, dark: string}, accent: array{light: string, dark: string}}, chart: array{light: list<string>, dark: list<string>}, kpi: array<string, array{light: string, dark: string}>}
     */
    public static function normalise(array $theme): array
    {
        $clean = self::defaults();

        foreach (array_keys(self::fields()) as $path) {
            $value = Arr::get($theme, $path);
            if (Contrast::isHex($value)) {
                Arr::set($clean, $path, Contrast::normalise($value));
            }
        }

        // Arr::set keeps the shape of the defaults (every path exists there).
        /** @var array{brand: array{primary: array{light: string, dark: string}, accent: array{light: string, dark: string}}, chart: array{light: list<string>, dark: list<string>}, kpi: array<string, array{light: string, dark: string}>} $clean */
        return $clean;
    }
}
