<?php

namespace App\Console\Commands;

use App\Support\GoLive\DataStatus;
use Illuminate\Console\Command;

/**
 * Production data loading status (Phase 9B.1): what is loaded, what is
 * missing and whether any synthetic or demo data exists. Exits 1 when demo
 * data exists or required data (hierarchy, LGA boundaries) is missing.
 * Prints counts and reference names only.
 */
class DataStatusCommand extends Command
{
    protected $signature = 'buffalo:data:status {--json : Print the status as JSON}';

    protected $description = 'Show which production data is loaded or missing, and whether any demo or synthetic data exists';

    public function handle(DataStatus $status): int
    {
        $data = $status->build();
        $required = array_values(array_filter($data['missing'], fn (string $item): bool => ! str_contains($item, '(optional)')));
        $ready = $data['demo'] === [] && $required === [];

        if ($this->option('json')) {
            $this->line((string) json_encode([...$data, 'reference_version' => $status->referenceVersion(), 'ready' => $ready], JSON_PRETTY_PRINT));

            return $ready ? self::SUCCESS : self::FAILURE;
        }

        $h = $data['hierarchy'];
        $this->components->twoColumnDetail('<fg=gray>Hierarchy</>');
        foreach (['states' => 'States', 'districts' => 'Senatorial districts', 'lgas' => 'LGAs'] as $level => $label) {
            $this->components->twoColumnDetail($label, sprintf('%d / %d', $h[$level], $h['expected'][$level]));
        }
        $this->components->twoColumnDetail('Wards (optional)', (string) $h['wards']);

        $b = $data['boundaries'];
        $this->components->twoColumnDetail('<fg=gray>Boundaries</>');
        $this->components->twoColumnDetail('LGAs with a boundary', sprintf('%d / %d', $b['lgas_with_boundary'], $h['lgas']));
        $this->components->twoColumnDetail('States with a boundary', (string) $b['states_with_boundary']);
        foreach ($b['sources'] as $source) {
            $this->components->twoColumnDetail(
                sprintf('  %s: %s%s', $source['level'], $source['source'], $source['synthetic'] ? ' <fg=red>(SYNTHETIC)</>' : ''),
                sprintf('%d features, %s', $source['features'], substr($source['imported_at'], 0, 10)),
            );
        }

        $this->components->twoColumnDetail('<fg=gray>Communities</>', (string) $data['communities']);

        $this->components->twoColumnDetail('<fg=gray>Reference data</>', 'version '.$status->referenceVersion());
        foreach ($data['reference'] as $list) {
            $this->components->twoColumnDetail($list['label'], sprintf('%d active  <fg=gray>%s</>', $list['active'], $list['fingerprint']));
        }

        $this->newLine();
        foreach ($data['missing'] as $item) {
            str_contains($item, '(optional)') ? $this->components->warn("Missing: {$item}") : $this->components->error("Missing: {$item}");
        }
        foreach ($data['demo'] as $item) {
            $this->components->error(sprintf('Demo or synthetic data: %d × %s', $item['count'], $item['detail']));
        }

        if ($ready) {
            $this->components->info('No demo or synthetic data; the required production data is loaded.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
