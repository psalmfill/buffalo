<?php

use App\Support\Incidents\IncidentReferenceAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reference numbers under concurrent submissions (Phase 5C). Real parallel
 * transactions: forked processes, each on its own database connection,
 * run the allocator's statement inside transactions (some rolled back)
 * against a state committed outside the test's own transaction. The
 * committed numbers must be exactly 1..N, without gaps or repeats.
 */
test('reference sequences stay gap-free and unique under parallel transactions', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is not available.');
    }

    $config = config('database.connections.pgsql');
    config(['database.connections.pgsql_side' => $config]);
    $side = DB::connection('pgsql_side');
    $code = 'Z'.Str::upper(Str::random(6));

    $zoneId = $side->table('geopolitical_zones')->insertGetId(['ulid' => (string) Str::ulid(), 'code' => $code, 'name' => "Zone {$code}", 'created_at' => now(), 'updated_at' => now()]);
    $stateId = $side->table('states')->insertGetId(['ulid' => (string) Str::ulid(), 'geopolitical_zone_id' => $zoneId, 'code' => $code, 'name' => "State {$code}", 'created_at' => now(), 'updated_at' => now()]);

    $workers = 4;
    $perWorker = 25;
    $files = [];
    $pids = [];

    try {
        for ($w = 0; $w < $workers; $w++) {
            $file = (string) tempnam(sys_get_temp_dir(), 'refs');
            $files[] = $file;
            $pid = pcntl_fork();

            if ($pid === 0) {
                // Child: its own connection; never touches the parent's.
                $pdo = new PDO(
                    sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
                    $config['username'],
                    $config['password'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
                );
                $committed = [];

                for ($i = 0; $i < $perWorker + 5; $i++) {
                    $pdo->beginTransaction();
                    $statement = $pdo->prepare(IncidentReferenceAllocator::NEXT_SQL);
                    $statement->execute([$stateId, 2026]);
                    $value = (int) $statement->fetchColumn();
                    usleep(random_int(0, 3000));

                    // Every sixth submission fails and rolls back.
                    if ($i % 6 === 5) {
                        $pdo->rollBack();
                    } else {
                        $pdo->commit();
                        $committed[] = $value;
                    }
                }

                file_put_contents($file, implode(',', $committed));
                // Leave without running the parent's shutdown handlers.
                posix_kill(posix_getpid(), SIGKILL);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $values = [];
        foreach ($files as $file) {
            $values = [...$values, ...array_map('intval', array_filter(explode(',', (string) file_get_contents($file)), 'strlen'))];
        }
        sort($values);

        expect($values)->toBe(range(1, count($values)))
            ->and(count($values))->toBe($workers * $perWorker)
            ->and((int) $side->table('incident_reference_counters')->where('state_id', $stateId)->value('last_value'))->toBe(count($values));
    } finally {
        foreach ($files as $file) {
            @unlink($file);
        }
        $side->table('incident_reference_counters')->where('state_id', $stateId)->delete();
        $side->table('states')->where('id', $stateId)->delete();
        $side->table('geopolitical_zones')->where('id', $zoneId)->delete();
        DB::purge('pgsql_side');
    }
});
