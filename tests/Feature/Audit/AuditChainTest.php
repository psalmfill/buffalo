<?php

use App\Enums\AccessAction;
use App\Models\User;
use App\Notifications\AuditChainBroken;
use App\Support\Audit\AuditChain;
use App\Support\Audit\AuditLog;
use App\Support\Audit\RecordAccessLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Process\Process;

/*
| Phase 8A.4: hash chains on the append-only audit tables.
|
| Tampering needs the superuser, in its own session, so those tests insert
| committed rows into login_histories through `pgsql_admin` and remove
| them (and restore the chain head) afterwards.
*/

/** Inserts committed login_histories rows as the superuser; returns their ids. */
function committedLogins(int $count, string $tag): array
{
    $admin = DB::connection('pgsql_admin');
    $ids = [];
    foreach (range(1, $count) as $i) {
        $ids[] = (int) $admin->table('login_histories')->insertGetId(['email_attempted' => "{$tag}{$i}@chain.test", 'event' => 'login_failed', 'created_at' => now()]);
    }

    return $ids;
}

/** Removes committed rows and puts the chain head back (superuser, triggers off). */
function cleanUpLogins(string $tag, object $head): void
{
    $admin = DB::connection('pgsql_admin');
    $admin->transaction(function () use ($admin, $tag, $head) {
        $admin->statement("SET LOCAL session_replication_role = 'replica'");
        $admin->table('login_histories')->where('email_attempted', 'like', "{$tag}%@chain.test")->delete();
        $admin->table('audit_chain_heads')->where('table_name', 'login_histories')->update(['last_seq' => $head->last_seq, 'last_hash' => $head->last_hash, 'last_row_id' => $head->last_row_id]);
    });
}

function chainHead(string $table): object
{
    return DB::connection('pgsql_admin')->table('audit_chain_heads')->where('table_name', $table)->first();
}

/** Alters or deletes committed rows as the superuser, bypassing the append-only triggers. */
function tamper(string $sql, array $bindings = []): void
{
    $admin = DB::connection('pgsql_admin');
    $admin->transaction(function () use ($admin, $sql, $bindings) {
        $admin->statement("SET LOCAL session_replication_role = 'replica'");
        $admin->statement($sql, $bindings);
    });
}

test('inserting builds a valid chain on every audit table', function () {
    $user = User::factory()->create();
    foreach (range(1, 3) as $i) {
        AuditLog::event('chain_test', "Chain test {$i}", $user);
        app(RecordAccessLogger::class)->log($user, AccessAction::View, "chain {$i}");
    }

    $rows = DB::table('activity_log')->orderBy('chain_seq')->get(['chain_seq', 'prev_hash', 'row_hash']);
    expect($rows->count())->toBeGreaterThanOrEqual(3);
    foreach ($rows as $i => $row) {
        expect($row->prev_hash)->toBe($i === 0 ? AuditChain::GENESIS : $rows[$i - 1]->row_hash)
            ->and((int) $row->chain_seq)->toBe($i + 1);
    }

    foreach (array_keys(AuditChain::TABLES) as $table) {
        expect(AuditChain::verify($table))->toMatchArray(['table' => $table, 'ok' => true]);
    }
    expect(Artisan::call('buffalo:audit:verify'))->toBe(0);
});

test('hashes do not depend on the session time zone or date style', function () {
    $user = User::factory()->create();
    DB::table('search_logs')->insert(['user_id' => $user->id, 'query_hash' => str_repeat('a', 64), 'query_type' => 'text', 'result_count' => 3, 'created_at' => now()]);

    DB::transaction(function () {
        DB::statement("SET LOCAL timezone = 'Pacific/Auckland'");
        DB::statement("SET LOCAL datestyle = 'SQL, DMY'");
        expect(AuditChain::verify('search_logs')['ok'])->toBeTrue();
    });
});

test('the application cannot write the chain head or the chain columns', function () {
    expect(fn () => DB::transaction(fn () => DB::table('audit_chain_heads')->update(['last_seq' => 0])))
        ->toThrow(QueryException::class, 'permission denied for table audit_chain_heads');

    // A forged hash on insert is replaced by the trigger.
    DB::table('login_histories')->insert(['email_attempted' => 'forge@chain.test', 'event' => 'login_failed', 'created_at' => now(), 'row_hash' => str_repeat('f', 64), 'chain_seq' => 999999]);
    $row = DB::table('login_histories')->where('email_attempted', 'forge@chain.test')->first();
    expect($row->row_hash)->not->toBe(str_repeat('f', 64))->and((int) $row->chain_seq)->not->toBe(999999)
        ->and(AuditChain::verify('login_histories')['ok'])->toBeTrue();
});

test('editing a past row by hand is reported at that row, and super admins and the DG are notified', function () {
    Notification::fake();
    $admin = User::factory()->superAdmin()->create();
    $dg = User::factory()->directorGeneral()->create();
    $chief = User::factory()->lgaChief()->create();
    $head = chainHead('login_histories');

    try {
        $ids = committedLogins(5, 'edit');
        expect(AuditChain::verify('login_histories')['ok'])->toBeTrue();

        tamper('UPDATE login_histories SET email_attempted = ? WHERE id = ?', ['edit-changed@chain.test', $ids[2]]);

        $result = AuditChain::verify('login_histories');
        expect($result['ok'])->toBeFalse()
            ->and($result['broken']['row_id'])->toBe($ids[2])
            ->and($result['broken']['reason'])->toBe('the row was altered')
            ->and($result['broken']['at'])->not->toBeNull();

        expect(Artisan::call('buffalo:audit:verify', ['--table' => 'login_histories']))->toBe(1)
            ->and(Artisan::output())->toContain("BROKEN at row {$ids[2]}");

        Notification::assertSentTo([$admin, $dg], AuditChainBroken::class, function (AuditChainBroken $n) use ($ids, $admin) {
            $payload = $n->toArray($admin);

            return $n->table === 'login_histories' && $n->rowId === $ids[2]
                && $payload['severity'] === 'critical'
                && ! str_contains(json_encode($payload), 'edit3@chain.test');
        });
        Notification::assertNotSentTo($chief, AuditChainBroken::class);
    } finally {
        cleanUpLogins('edit', $head);
    }
});

test('deleting a row is detected at the right position, and deleting the last rows too', function () {
    $head = chainHead('login_histories');

    try {
        $ids = committedLogins(5, 'delete');

        // A row in the middle: the next row no longer follows.
        tamper('DELETE FROM login_histories WHERE id = ?', [$ids[1]]);
        $result = AuditChain::verify('login_histories');
        expect($result['ok'])->toBeFalse()
            ->and($result['broken']['row_id'])->toBe($ids[2])
            ->and($result['broken']['reason'])->toContain('missing');
        cleanUpLogins('delete', $head);

        // The last row: the head is further on than the last row.
        $ids = committedLogins(3, 'delete');
        tamper('DELETE FROM login_histories WHERE id = ?', [$ids[2]]);
        $result = AuditChain::verify('login_histories');
        expect($result['ok'])->toBeFalse()
            ->and($result['broken']['row_id'])->toBe($ids[2])
            ->and($result['broken']['reason'])->toBe('the last rows of the chain are missing (deleted)');
    } finally {
        cleanUpLogins('delete', $head);
    }
});

test('verification can start from a date', function () {
    $head = chainHead('login_histories');

    try {
        $ids = committedLogins(3, 'since');
        tamper("UPDATE login_histories SET created_at = now() - interval '10 days' WHERE id = ?", [$ids[0]]);

        // From yesterday: the backdated (altered) row is before the window, and the
        // later rows still link to its stored hash, so the window checks out.
        $window = AuditChain::verify('login_histories', now()->subDay());
        expect($window['ok'])->toBeTrue()->and($window['checked'])->toBe(2);
        // The full chain finds the altered row itself.
        expect(AuditChain::verify('login_histories')['broken']['row_id'])->toBe($ids[0]);
    } finally {
        cleanUpLogins('since', $head);
    }
});

test('concurrent inserts from several sessions keep a valid chain', function () {
    $head = chainHead('login_histories');
    $config = config('database.connections.pgsql');
    $script = <<<'PHP'
        $c = json_decode($argv[1], true);
        $pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}", $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $insert = $pdo->prepare("INSERT INTO login_histories (email_attempted, event, created_at) VALUES (?, 'login_failed', now())");
        for ($i = 0; $i < 25; $i++) {
            $pdo->beginTransaction();
            $insert->execute(["concurrent{$argv[2]}-{$i}@chain.test"]);
            usleep(random_int(0, 2000));
            $pdo->commit();
        }
        PHP;

    try {
        $processes = [];
        foreach (range(1, 4) as $worker) {
            $process = new Process(['php', '-r', $script, json_encode($config), (string) $worker]);
            $process->start();
            $processes[] = $process;
        }
        foreach ($processes as $process) {
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
        }

        $rows = DB::table('login_histories')->where('email_attempted', 'like', 'concurrent%@chain.test')->count();
        $result = AuditChain::verify('login_histories');
        expect($rows)->toBe(100)
            ->and($result['ok'])->toBeTrue()
            ->and((int) chainHead('login_histories')->last_seq)->toBe((int) $head->last_seq + 100);
    } finally {
        cleanUpLogins('concurrent', $head);
    }
});

test('anchors record every chain head', function () {
    expect(Artisan::call('buffalo:audit:anchor'))->toBe(0);

    $anchors = DB::table('audit_anchors')->pluck('head_hash', 'table_name');
    expect($anchors->keys()->sort()->values()->all())->toBe(array_keys(AuditChain::TABLES))
        ->and($anchors['login_histories'])->toBe(DB::table('audit_chain_heads')->where('table_name', 'login_histories')->value('last_hash'));
    expect(fn () => DB::transaction(fn () => DB::table('audit_anchors')->delete()))->toThrow(QueryException::class, 'permission denied for table audit_anchors');
});
