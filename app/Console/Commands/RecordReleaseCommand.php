<?php

namespace App\Console\Commands;

use App\Support\GoLive\GoLiveCheck;
use App\Support\Health\HealthStatus;
use Illuminate\Console\Command;

/**
 * Records the security scan summary of the installed release (Phase 9B.6),
 * read from standard input: install.sh and upgrade.sh pass the
 * security-scan.json of the signed bundle. The go-live check compares it
 * with the version baked into the image.
 */
class RecordReleaseCommand extends Command
{
    protected $signature = 'buffalo:release:record {--file= : Read the JSON from this file instead of standard input}';

    protected $description = 'Record the installed release\'s security scan summary (JSON on standard input)';

    public function handle(): int
    {
        $file = $this->option('file');
        $raw = is_string($file) && $file !== '' ? (string) @file_get_contents($file) : (string) stream_get_contents(STDIN);
        $json = json_decode($raw, true);

        if (! is_array($json) || ! is_string($json['version'] ?? null) || ! in_array($json['result'] ?? null, ['passed', 'failed', 'skipped'], true)) {
            $this->components->error('Expected security-scan.json with "version" and "result" (passed, failed or skipped).');

            return self::FAILURE;
        }

        $summary = [
            'version' => $json['version'],
            'result' => $json['result'],
            'scanned_at' => is_string($json['scanned_at'] ?? null) ? $json['scanned_at'] : null,
            'commit' => is_string($json['commit'] ?? null) ? $json['commit'] : null,
            'scanner' => is_string($json['scanner'] ?? null) ? $json['scanner'] : null,
            'images' => is_array($json['images'] ?? null) ? $json['images'] : [],
        ];
        HealthStatus::record(GoLiveCheck::SCAN_KEY, $summary['result'] === 'passed', "Release {$summary['version']}: scan {$summary['result']}", $summary);

        $this->components->info("Recorded the security scan of release {$summary['version']}: {$summary['result']}.");

        return self::SUCCESS;
    }
}
