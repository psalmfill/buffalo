<?php

namespace App\Support\Csv;

/**
 * Result of reading a CSV file: data rows keyed by column name (values
 * trimmed) with their physical line numbers, plus any errors found while
 * reading. Errors are already prefixed with "<file>:<line>:".
 */
final readonly class CsvFile
{
    /**
     * @param  list<array{line: int, values: array<string, string>}>  $rows
     * @param  list<string>  $errors
     */
    public function __construct(
        public string $name,
        public array $rows,
        public array $errors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
