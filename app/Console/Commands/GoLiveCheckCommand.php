<?php

namespace App\Console\Commands;

use App\Support\GoLive\GoLiveCheck;
use App\Support\Health\HealthStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Go-live check (Phase 9B.6): every technical item must pass and every
 * client decision must be recorded; exits 1 until then. The result is
 * stored for System health and written as a Markdown report (no secrets)
 * to the private disk for the client's records.
 */
class GoLiveCheckCommand extends Command
{
    protected $signature = 'buffalo:go-live:check
        {--no-report : Do not write the Markdown report}
        {--record : Only record the result for System health and exit 0 (the daily scheduled run)}';

    protected $description = 'Check that the system is ready to go live (technical checks and recorded client decisions)';

    private const MARK = ['pass' => '✓ pass', 'fail' => '✗ FAIL', 'recorded' => '✓ recorded', 'missing' => '✗ MISSING'];

    public function handle(GoLiveCheck $check): int
    {
        $result = $check->run();
        HealthStatus::record(GoLiveCheck::STATUS_KEY, $result['passed'], $result['passed'] ? 'Ready to go live.' : 'Not ready.', $result);

        foreach (['technical' => 'Technical (must pass)', 'decision' => 'Client decisions (must be recorded)'] as $section => $title) {
            $this->newLine();
            $this->components->twoColumnDetail("<fg=gray>{$title}</>");
            foreach (array_filter($result['items'], fn (array $item) => $item['section'] === $section) as $item) {
                $ok = in_array($item['status'], ['pass', 'recorded'], true);
                $this->components->twoColumnDetail($item['label'], $ok ? '<fg=green>'.self::MARK[$item['status']].'</>' : '<fg=red>'.self::MARK[$item['status']].'</>');
                $this->line('    <fg=gray>'.$item['detail'].'</>');
            }
        }

        if ($this->option('record')) {
            return self::SUCCESS;
        }

        if (! $this->option('no-report')) {
            $path = 'go-live/'.now()->format('Y-m-d-Hi').'.md';
            Storage::disk((string) config('buffalo.golive.report_disk'))->put($path, self::markdown($result));
            $this->newLine();
            $this->components->info("Report written to storage ({$path}).");
        }

        if ($result['passed']) {
            $this->components->info('Ready to go live: every technical check passes and every client decision is recorded.');

            return self::SUCCESS;
        }

        $this->components->error('Not ready to go live.');

        return self::FAILURE;
    }

    /**
     * @param  array{passed: bool, version: string, checked_at: string, items: list<array{section: string, key: string, label: string, status: string, detail: string}>}  $result
     */
    public static function markdown(array $result): string
    {
        $lines = [
            '# BUFFALO go-live check',
            '',
            '- Checked: '.$result['checked_at'],
            '- Version: '.$result['version'],
            '- Result: **'.($result['passed'] ? 'READY' : 'NOT READY').'**',
        ];

        foreach (['technical' => 'Technical checks (must pass)', 'decision' => 'Client decisions (must be recorded)'] as $section => $title) {
            $lines[] = '';
            $lines[] = "## {$title}";
            $lines[] = '';
            $lines[] = '| Item | Result | Detail |';
            $lines[] = '|---|---|---|';
            foreach (array_filter($result['items'], fn (array $item) => $item['section'] === $section) as $item) {
                $lines[] = sprintf('| %s | %s | %s |', $item['label'], self::MARK[$item['status']], str_replace('|', '\\|', $item['detail']));
            }
        }

        return implode("\n", $lines)."\n";
    }
}
