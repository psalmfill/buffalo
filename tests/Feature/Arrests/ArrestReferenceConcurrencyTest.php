<?php

use App\Support\Arrests\ArrestReferenceAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Arrest references under concurrent creation (Phase 11C), as for threat
 * report references: forked processes, each on its own connection, run the
 * allocator's statement in transactions (some rolled back) against a state
 * committed outside the test's transaction. Kept in its own file: other
 * set-up in the test's transaction would block the side connection.
 */
test('arrest reference sequences stay gap-free and unique under parallel transactions', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is not available.');
    }

    $config = config('database.connections.pgsql');
    config(['database.connections.pgsql_side' => $config]);
    $side = DB::connection('pgsql_side');
    $code = 'Z'.Str::upper(Str::random(6));
    $zoneId = $side->table('geopolitical_zones')->insertGetId(['ulid' => (string) Str::ulid(), 'code' => $code, 'name' => "Zone {$code}", 'created_at' => now(), 'updated_at' => now()]);
    $stateId = $side->table('states')->insertGetId(['ulid' => (string) Str::ulid(), 'geopolitical_zone_id' => $zoneId, 'code' => $code, 'name' => "State {$code}", 'created_at' => now(), 'updated_at' => now()]);
    $files = [];
    $pids = [];

    try {
        for ($w = 0; $w < 4; $w++) {
            $file = (string) tempnam(sys_get_temp_dir(), 'arr');
            $files[] = $file;
            $pid = pcntl_fork();

            if ($pid === 0) {
                $pdo = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']), $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $committed = [];

                for ($i = 0; $i < 30; $i++) {
                    $pdo->beginTransaction();
                    $statement = $pdo->prepare(ArrestReferenceAllocator::NEXT_SQL);
                    $statement->execute([$stateId, 2026]);
                    $value = (int) $statement->fetchColumn();
                    usleep(random_int(0, 3000));

                    if ($i % 6 === 5) {
                        $pdo->rollBack();
                    } else {
                        $pdo->commit();
                        $committed[] = $value;
                    }
                }

                file_put_contents($file, implode(',', $committed));
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

        expect($values)->toBe(range(1, 100));
    } finally {
        foreach ($files as $file) {
            @unlink($file);
        }
        $side->table('arrest_counters')->where('state_id', $stateId)->delete();
        $side->table('states')->where('id', $stateId)->delete();
        $side->table('geopolitical_zones')->where('id', $zoneId)->delete();
        DB::purge('pgsql_side');
    }
});
