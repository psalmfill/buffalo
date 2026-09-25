<?php

namespace App\Support\Csv;

/**
 * Strict CSV reader for owner-supplied reference data.
 *
 * - UTF-8 only (a leading BOM is ignored).
 * - The header must contain exactly the expected columns, in any order.
 * - Every row must have as many fields as the header; blank lines are skipped.
 * - Values are trimmed. Quoted fields may contain commas and line breaks.
 *
 * It collects every problem instead of stopping at the first one, so callers
 * can report all errors together.
 */
final class CsvReader
{
    /**
     * @param  list<string>  $columns  expected header columns
     */
    public function read(string $path, array $columns): CsvFile
    {
        $name = basename($path);

        if (! is_file($path) || ! is_readable($path)) {
            return new CsvFile($name, [], ["{$name}: file not found or not readable."]);
        }

        $contents = (string) file_get_contents($path);

        if (! mb_check_encoding($contents, 'UTF-8')) {
            return new CsvFile($name, [], ["{$name}: file is not valid UTF-8."]);
        }

        if (str_starts_with($contents, "\u{FEFF}")) {
            $contents = substr($contents, 3);
        }

        if (trim($contents) === '') {
            return new CsvFile($name, [], ["{$name}: file is empty; a header row is required."]);
        }

        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, $contents);
        rewind($stream);

        $errors = [];
        $rows = [];
        $header = null;
        $line = 1;
        $position = 0;

        while (($fields = fgetcsv($stream, escape: '')) !== false) {
            $recordLine = $line;
            $next = (int) ftell($stream);
            $line += substr_count($contents, "\n", $position, $next - $position);
            $position = $next;

            // A blank line comes back as [null].
            if ($fields === [null]) {
                continue;
            }

            $fields = array_map(fn (?string $value): string => trim((string) $value), $fields);

            if ($header === null) {
                $header = $fields;
                $headerErrors = $this->validateHeader($name, $recordLine, $header, $columns);

                if ($headerErrors !== []) {
                    fclose($stream);

                    return new CsvFile($name, [], $headerErrors);
                }

                continue;
            }

            if (count($fields) !== count($header)) {
                $errors[] = sprintf(
                    '%s:%d: expected %d fields but found %d (quote values that contain commas).',
                    $name,
                    $recordLine,
                    count($header),
                    count($fields),
                );

                continue;
            }

            $rows[] = [
                'line' => $recordLine,
                'values' => array_combine($header, $fields),
            ];
        }

        fclose($stream);

        return new CsvFile($name, $rows, $errors);
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function validateHeader(string $name, int $line, array $header, array $columns): array
    {
        $errors = [];
        $expected = implode(',', $columns);

        $duplicates = array_keys(array_filter(array_count_values($header), fn (int $count): bool => $count > 1));
        $missing = array_values(array_diff($columns, $header));
        $unknown = array_values(array_diff($header, $columns));

        if ($duplicates !== []) {
            $errors[] = "{$name}:{$line}: duplicate header column(s): ".implode(', ', $duplicates).'.';
        }

        if ($missing !== []) {
            $errors[] = "{$name}:{$line}: missing header column(s): ".implode(', ', $missing).". Expected: {$expected}.";
        }

        if ($unknown !== []) {
            $errors[] = "{$name}:{$line}: unknown header column(s): ".implode(', ', $unknown).". Expected: {$expected}.";
        }

        return $errors;
    }
}
