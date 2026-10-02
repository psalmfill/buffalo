<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\AuditChainBroken;
use App\Support\Audit\AuditChain;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Walks the audit hash chains and reports the first broken link per table
 * (Phase 8A.4). Scheduled nightly; any failure notifies super admins and
 * the DG (critical; table name and row ID only) and exits with an error.
 */
class VerifyAuditChains extends Command
{
    protected $signature = 'buffalo:audit:verify
        {--table= : Check one table only}
        {--since= : Check rows from this date (Y-m-d) onwards}';

    protected $description = 'Verify the audit hash chains and report the first broken link';

    public function handle(): int
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
        }

        $this->table(['Table', 'Rows checked', 'Result'], $rows);

        if ($broken === []) {
            $this->components->info('Every audit chain is intact.');

            return self::SUCCESS;
        }

        $recipients = User::query()->role([Role::SuperAdmin->value, Role::DirectorGeneral->value])->where('is_active', true)->get();
        foreach ($broken as $result) {
            Log::critical('Audit chain broken', ['table' => $result['table'], 'row_id' => $result['broken']['row_id']]);
            Notification::send($recipients, new AuditChainBroken($result['table'], $result['broken']['row_id']));
        }

        $this->components->error(count($broken).' audit chain(s) failed verification. Super admins and the DG have been notified.');

        return self::FAILURE;
    }
}
