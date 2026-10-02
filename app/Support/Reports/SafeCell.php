<?php

namespace App\Support\Reports;

/**
 * Spreadsheet formula injection protection (Phase 7D, CLAUDE.md decisions
 * log): a text value starting with `=`, `+`, `-`, `@`, tab or carriage
 * return is prefixed with a single quote, in XLSX and CSV alike. Numbers
 * are left as numbers (a negative number is not text).
 */
final class SafeCell
{
    public static function value(mixed $value): string|int|float|null
    {
        if ($value === null || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        $text = (string) $value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }
}
