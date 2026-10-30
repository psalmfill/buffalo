<?php

namespace App\Actions\Hierarchy;

/**
 * Code and name rules shared by the CSV import (ReadHierarchyFiles) and
 * Administration › Hierarchy, so an entry created on screen would pass the
 * import unchanged. Documented in database/data/README.md.
 */
final class HierarchyRules
{
    public const EXPECTED_STATES = 37;

    public const EXPECTED_DISTRICTS = 109;

    public const EXPECTED_LGAS = 774;

    public const MAX_NAME_LENGTH = 255;

    /** Three uppercase letters, unique within the state. */
    public const LGA_CODE = '/^[A-Z]{3}$/';

    /** 1–20 uppercase letters, digits or hyphens, starting with a letter or digit; unique within the LGA. */
    public const WARD_CODE = '/^[A-Z0-9][A-Z0-9-]{0,19}$/';

    /**
     * `<STATE>-<X>`: the state code, a hyphen, then uppercase letters or
     * digits, at most 20 characters; globally unique.
     */
    public static function validDistrictCode(string $code, string $stateCode): bool
    {
        return str_starts_with($code, "{$stateCode}-")
            && preg_match('/^[A-Z0-9]+$/', substr($code, strlen($stateCode) + 1)) === 1
            && strlen($code) <= 20;
    }

    public static function validLgaCode(string $code): bool
    {
        return preg_match(self::LGA_CODE, $code) === 1;
    }

    public static function validWardCode(string $code): bool
    {
        return preg_match(self::WARD_CODE, $code) === 1;
    }
}
