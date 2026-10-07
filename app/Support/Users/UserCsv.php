<?php

namespace App\Support\Users;

use Illuminate\Validation\ValidationException;
use SplFileObject;

/**
 * The bulk user import file (Phase 9B.2): UTF-8 CSV with exactly these
 * columns, in any order. `zone_codes` (DDG only) lists zone codes separated
 * by semicolons. Read from PHP's temporary upload; never stored.
 */
final class UserCsv
{
    public const HEADER = ['name', 'email', 'phone', 'service_number', 'rank', 'role', 'state_code', 'lga_code', 'zone_codes'];

    public const MAX_ROWS = 2000;

    public static function template(): string
    {
        return implode(',', self::HEADER)."\n";
    }

    /**
     * @return list<array<string, string|int>> one array per data row: the
     *                                         columns plus `line` (file line)
     *
     * @throws ValidationException (key "file")
     */
    public static function read(string $path): array
    {
        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);

        $header = null;
        $rows = [];

        foreach ($file as $index => $fields) {
            if (! is_array($fields) || $fields === [null] || $fields === []) {
                continue;
            }

            $fields = array_map(fn (mixed $value): string => trim((string) $value), $fields);

            if ($header === null) {
                $fields[0] = (string) preg_replace('/^\x{FEFF}/u', '', $fields[0]);
                $header = array_values(array_map('strtolower', $fields));
                self::checkHeader($header);

                continue;
            }

            if (! mb_check_encoding(implode('', $fields), 'UTF-8')) {
                throw ValidationException::withMessages(['file' => 'The file must be UTF-8 encoded (line '.((int) $index + 1).').']);
            }

            if (count($fields) !== count($header)) {
                throw ValidationException::withMessages(['file' => 'Line '.((int) $index + 1).' has '.count($fields).' fields; the header has '.count($header).'.']);
            }

            if (count($rows) >= self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => 'The file has more than '.self::MAX_ROWS.' rows. Split it into smaller files.']);
            }

            $rows[] = ['line' => (int) $index + 1, ...array_combine($header, $fields)];
        }

        if ($header === null) {
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The file has a header but no rows.']);
        }

        return $rows;
    }

    /**
     * @param  list<string>  $header
     */
    private static function checkHeader(array $header): void
    {
        $missing = array_diff(self::HEADER, $header);
        $unknown = array_diff($header, self::HEADER);

        if ($missing !== [] || $unknown !== [] || count($header) !== count(array_unique($header))) {
            throw ValidationException::withMessages(['file' => 'The header must be exactly: '.implode(',', self::HEADER).'. '
                .($missing !== [] ? 'Missing: '.implode(', ', $missing).'. ' : '')
                .($unknown !== [] ? 'Unknown: '.implode(', ', $unknown).'.' : '')]);
        }
    }
}
