<?php

namespace App\Support\Evidence;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reads the few EXIF values evidence needs (Phase 5B), once, at upload:
 * camera make and model, the original timestamp, and the GPS position.
 * The original file is never changed.
 *
 * `DateTimeOriginal` carries no time zone. `OffsetTimeOriginal` is used when
 * present; otherwise the time is read in the app time zone (Africa/Lagos).
 */
final class ExifReader
{
    /**
     * @return array{summary: array<string, mixed>|null, captured_at: CarbonImmutable|null, latitude: float|null, longitude: float|null}
     */
    public function read(string $path): array
    {
        $none = ['summary' => null, 'captured_at' => null, 'latitude' => null, 'longitude' => null];

        if (! function_exists('exif_read_data')) {
            return $none;
        }

        $exif = @exif_read_data($path);

        if (! is_array($exif)) {
            return $none;
        }

        $capturedAt = $this->capturedAt($exif);
        [$latitude, $longitude] = $this->position($exif);

        $summary = array_filter([
            'make' => $this->text($exif['Make'] ?? null),
            'model' => $this->text($exif['Model'] ?? null),
            'datetime_original' => $this->text($exif['DateTimeOriginal'] ?? null),
            'offset_time_original' => $this->text($exif['OffsetTimeOriginal'] ?? null),
            'gps_latitude' => $latitude,
            'gps_longitude' => $longitude,
        ], fn (mixed $value) => $value !== null);

        return [
            'summary' => $summary === [] ? null : $summary,
            'captured_at' => $capturedAt,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    /**
     * @param  array<string, mixed>  $exif
     */
    private function capturedAt(array $exif): ?CarbonImmutable
    {
        $value = $this->text($exif['DateTimeOriginal'] ?? null);

        if ($value === null || ! preg_match('/^\d{4}:\d{2}:\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return null;
        }

        $offset = $this->text($exif['OffsetTimeOriginal'] ?? null);
        $zone = $offset !== null && preg_match('/^[+-]\d{2}:\d{2}$/', $offset) ? $offset : (string) config('app.timezone');

        try {
            $at = CarbonImmutable::createFromFormat('Y:m:d H:i:s', $value, $zone);
        } catch (Throwable) {
            return null;
        }

        // Cameras with an unset clock write 0000:00:00 or dates in the future.
        if ($at === null || $at->year < 1990 || $at->isAfter(CarbonImmutable::now()->addDay())) {
            return null;
        }

        return $at->setTimezone((string) config('app.timezone'));
    }

    /**
     * @param  array<string, mixed>  $exif
     * @return array{0: float|null, 1: float|null}
     */
    private function position(array $exif): array
    {
        $latitude = $this->degrees($exif['GPSLatitude'] ?? null, $exif['GPSLatitudeRef'] ?? null, 'S');
        $longitude = $this->degrees($exif['GPSLongitude'] ?? null, $exif['GPSLongitudeRef'] ?? null, 'W');

        if ($latitude === null || $longitude === null || abs($latitude) > 90 || abs($longitude) > 180) {
            return [null, null];
        }

        return [round($latitude, 7), round($longitude, 7)];
    }

    /**
     * Degrees, minutes, seconds (EXIF rationals such as "6/1") to decimal.
     */
    private function degrees(mixed $parts, mixed $ref, string $negative): ?float
    {
        if (! is_array($parts) || count($parts) !== 3) {
            return null;
        }

        $values = [];
        foreach (array_values($parts) as $part) {
            $value = $this->rational($part);

            if ($value === null) {
                return null;
            }
            $values[] = $value;
        }

        $decimal = $values[0] + $values[1] / 60 + $values[2] / 3600;

        return is_string($ref) && strtoupper(trim($ref)) === $negative ? -$decimal : $decimal;
    }

    private function rational(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value) || ! preg_match('#^(\d+)/(\d+)$#', $value, $m) || (int) $m[2] === 0) {
            return null;
        }

        return (int) $m[1] / (int) $m[2];
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $value));

        return $clean === '' ? null : mb_substr($clean, 0, 100);
    }
}
