<?php

namespace App\Actions\Hierarchy;

/**
 * Outcome of a hierarchy import: validation problems, per-level counts and
 * the records that are in the database but missing from the files.
 */
final class HierarchyImportReport
{
    public const LEVELS = ['Senatorial districts', 'LGAs', 'Wards'];

    /** @var array<string, array{created: int, updated: int, unchanged: int, missing: int, deactivated: int}> */
    public array $counts = [];

    /** @var array<string, list<string>> */
    public array $missing = [];

    /**
     * Active entries created on screen that are not in the files (never
     * deactivated by the import).
     *
     * @var array<string, list<array{entry: string, created_by: string|null, created_at: string|null, reason: string|null}>>
     */
    public array $onScreen = [];

    public bool $written = false;

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $warnings,
        public readonly bool $dryRun,
        public readonly bool $wardsIncluded,
    ) {
        foreach (self::LEVELS as $level) {
            $this->counts[$level] = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'missing' => 0, 'deactivated' => 0];
            $this->missing[$level] = [];
            $this->onScreen[$level] = [];
        }
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @param  'created'|'updated'|'unchanged'|'missing'|'deactivated'  $outcome
     */
    public function count(string $level, string $outcome): void
    {
        $this->counts[$level][$outcome]++;
    }
}
