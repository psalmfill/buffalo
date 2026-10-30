<?php

namespace App\Console\Commands;

use App\Actions\Hierarchy\HierarchyImportReport;
use App\Actions\Hierarchy\ImportHierarchy;
use Illuminate\Console\Command;

/**
 * Imports senatorial districts, LGAs and wards from the owner-supplied CSV
 * files. File formats and rules: database/data/README.md.
 */
class ImportHierarchyCommand extends Command
{
    protected $signature = 'buffalo:import-hierarchy
        {--path= : Directory containing the CSV files (default: database/data)}
        {--dry-run : Validate and report without writing}
        {--allow-count-mismatch : Treat totals other than 37 states / 109 districts / 774 LGAs as warnings}
        {--deactivate-missing : Deactivate districts, LGAs and wards that are in the database but not in the files}';

    protected $description = 'Import the administrative hierarchy (senatorial districts, LGAs, wards) from CSV files';

    public function handle(ImportHierarchy $import): int
    {
        $option = $this->option('path');
        $path = is_string($option) && $option !== '' ? $option : database_path('data');

        $this->components->info(sprintf(
            'Importing the hierarchy from %s%s',
            $path,
            $this->option('dry-run') ? ' (dry run: nothing will be written)' : '',
        ));

        $report = $import(
            $path,
            dryRun: (bool) $this->option('dry-run'),
            allowCountMismatch: (bool) $this->option('allow-count-mismatch'),
            deactivateMissing: (bool) $this->option('deactivate-missing'),
        );

        foreach ($report->warnings as $warning) {
            $this->components->warn($warning);
        }

        if ($report->hasErrors()) {
            $this->components->error(sprintf('Validation failed with %d error(s). Nothing was written.', count($report->errors)));

            foreach ($report->errors as $error) {
                $this->line("  <fg=red>✗</> {$error}");
            }

            return self::FAILURE;
        }

        $this->reportMissing($report);
        $this->reportOnScreen($report);
        $this->summary($report);

        if ($report->dryRun) {
            $this->components->info('Dry run complete. Nothing was written.');
        } else {
            $this->components->info('Import complete.');
        }

        return self::SUCCESS;
    }

    private function reportMissing(HierarchyImportReport $report): void
    {
        foreach ($report->missing as $level => $items) {
            if ($items === []) {
                continue;
            }

            $this->components->warn(sprintf(
                '%d %s in the database %s not in the files%s:',
                count($items),
                $level,
                count($items) === 1 ? 'is' : 'are',
                $this->option('deactivate-missing')
                    ? ($report->dryRun ? ' and would be deactivated' : ' and were deactivated')
                    : ' (left unchanged; use --deactivate-missing to deactivate them)',
            ));

            foreach ($items as $item) {
                $this->line("    {$item}");
            }
        }
    }

    /**
     * Entries created in Administration › Hierarchy that the official files
     * do not have: listed with who created them, when and why, and left
     * active (even with --deactivate-missing).
     */
    private function reportOnScreen(HierarchyImportReport $report): void
    {
        $any = false;

        foreach ($report->onScreen as $level => $items) {
            if ($items === []) {
                continue;
            }

            $any = true;
            $this->components->warn(sprintf('%d %s created on screen %s not in the files (left active):', count($items), $level, count($items) === 1 ? 'is' : 'are'));

            foreach ($items as $item) {
                $this->line(sprintf(
                    '    %s — created by %s on %s; reason: %s',
                    $item['entry'],
                    $item['created_by'] ?? 'unknown',
                    $item['created_at'] ?? 'unknown date',
                    $item['reason'] ?? '—',
                ));
            }
        }

        if ($any) {
            $this->line('  Add these to the official CSV, or deactivate them in Administration → Hierarchy if they are no longer needed.');
        }
    }

    private function summary(HierarchyImportReport $report): void
    {
        $rows = [];

        foreach ($report->counts as $level => $counts) {
            if ($level === 'Wards' && ! $report->wardsIncluded) {
                $rows[] = [$level, '—', '—', '—', '—', '—'];

                continue;
            }

            $rows[] = [$level, $counts['created'], $counts['updated'], $counts['unchanged'], $counts['missing'], $counts['deactivated']];
        }

        $this->table(
            [$report->dryRun ? 'Level (dry run)' : 'Level', 'Created', 'Updated', 'Unchanged', 'Missing from files', 'Deactivated'],
            $rows,
        );

        if (! $report->wardsIncluded) {
            $this->line('  wards.csv not found: wards were not imported.');
        }
    }
}
