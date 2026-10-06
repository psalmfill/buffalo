<?php

namespace App\Console\Commands;

use App\Notifications\AuditChainBroken;
use App\Support\Audit\AuditChain;
use App\Support\Health\Condition;
use App\Support\Health\HealthStatus;
use App\Support\Health\SystemAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Walks the audit hash chains and reports the first broken link per table
 * (Phase 8A.4). Scheduled nightly; any failure notifies super admins and
 * the DG (critical; table name and row ID only), once per broken table
 * until it verifies again (Phase 8C.4), and exits with an error.
 */
class VerifyAuditChains extends Command
{
    protected $signature = 'buffalo:audit:verify
        {--table= : Check one table only}
        {--since= : Check rows from this date (Y-m-d) onwards}';

    protected $description = 'Verify the audit hash chains and report the first broken link';

    public function handle(SystemAlerts $alerts): int
    {
        $tables = array_keys(AuditChain::TABLES);
        $only = (string) $this->option('table');

        if ($only !== '') {
            if (! in_array($only, $tables, true)) {
                $this->components->error("{$only} is not a chained audit table.");

                return self::INVALID;
            }
            $tables = [$only];
        }

        $since = $this->option('since') ? CarbonImmutable::parse((string) $this->option('since'), (string) config('app.timezone'))->startOfDay() : null;
        $rows = [];
        $broken = [];

        foreach ($tables as $table) {
            $result = AuditChain::verify($table, $since);
            $rows[] = [$table, number_format($result['checked']), $result['ok'] ? 'OK' : sprintf(
                'BROKEN at row %s%s: %s',
                $result['broken']['row_id'] ?? '?',
                $result['broken']['at'] === null ? '' : " ({$result['broken']['at']})",
                $result['broken']['reason'],
            )];

            if (! $result['ok']) {
                $broken[] = $result;
            }

            // One deduplicated alert per broken table (Phase 8C.4): sent when
            // first found, cleared when the chain verifies again.
            $alerts->sync("audit.chain.{$table}", $result['ok'] ? [] : [Condition::critical(
                "audit.chain.{$table}",
                'Audit records may have been tampered with',
                "The audit chain of {$table} is broken".($result['broken']['row_id'] === null ? '.' : " at row {$result['broken']['row_id']}."),
                alsoDirectorGeneral: true,
                notification: new AuditChainBroken($table, $result['broken']['row_id']),
            )]);
        }

        if ($only === '' && $since === null) {
            HealthStatus::record('audit.verify', $broken === [], $broken === [] ? 'Every audit chain is intact.' : count($broken).' chain(s) broken: '.implode(', ', array_column($broken, 'table')));
        }

        $this->table(['Table', 'Rows checked', 'Result'], $rows);

        if ($broken === []) {
            $this->components->info('Every audit chain is intact.');

            return self::SUCCESS;
        }

        foreach ($broken as $result) {
            Log::critical('Audit chain broken', ['table' => $result['table'], 'row_id' => $result['broken']['row_id']]);
        }

        $this->components->error(count($broken).' audit chain(s) failed verification. Super admins and the DG are notified (once until it is resolved).');

        return self::FAILURE;
    }
}
