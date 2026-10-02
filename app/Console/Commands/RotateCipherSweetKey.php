<?php

namespace App\Console\Commands;

use App\Models\OrganizationContact;
use App\Models\Person;
use App\Support\Audit\AuditLog;
use App\Support\Keys\KeyFingerprints;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use ParagonIE\CipherSweet\CipherSweet;
use ParagonIE\CipherSweet\EncryptedRow;
use ParagonIE\CipherSweet\KeyProvider\StringProvider;
use ParagonIE\CipherSweet\KeyRotation\RowRotator;
use Throwable;

/**
 * CIPHERSWEET_KEY rotation (Phase 8A.3).
 *
 * Re-encrypts every encrypted field and rebuilds every blind index under a
 * new key, using CipherSweet's own key rotation (RowRotator: the package's
 * supported approach, as in `ciphersweet:encrypt`), in batches, inside
 * maintenance mode:
 *
 * - The new key is read from a file (never the command line).
 * - Its fingerprint is recorded as pending and a `key_rotations` row keeps
 *   the progress per model, so an interrupted run resumes where it stopped.
 *   Rows that already decrypt under the new key are skipped.
 * - Blind indexes follow BUFFALO's rule: none for empty values.
 * - The application stays down afterwards: the running key no longer
 *   matches the data. The operator sets CIPHERSWEET_KEY to the new key and
 *   restarts; the start-up check completes the rotation; then
 *   `php artisan up`.
 *
 * The old key must stay in escrow while any backup made before the
 * rotation exists.
 */
class RotateCipherSweetKey extends Command
{
    protected $signature = 'buffalo:keys:rotate-ciphersweet
        {--new-key-file= : File holding the new key (64 hexadecimal characters)}
        {--batch=500 : Rows per batch}
        {--max-batches=0 : Stop after this many batches (0: run to the end); run again to resume}';

    protected $description = 'Re-encrypt all encrypted fields and blind indexes under a new CIPHERSWEET_KEY (resumable, in maintenance mode)';

    /** @var list<class-string<Person|OrganizationContact>> */
    public const MODELS = [Person::class, OrganizationContact::class];

    public function handle(): int
    {
        $file = (string) $this->option('new-key-file');
        $newKey = is_readable($file) ? trim((string) file_get_contents($file)) : '';

        if (preg_match('/^[0-9a-f]{64}$/i', $newKey) !== 1) {
            $this->components->error('Give --new-key-file=FILE holding the new key: 64 hexadecimal characters (php artisan buffalo:encryption-key --output=FILE).');

            return self::INVALID;
        }

        $currentKey = KeyFingerprints::runningKey('ciphersweet_key');
        $from = KeyFingerprints::fingerprint('ciphersweet_key', $currentKey);
        $to = KeyFingerprints::fingerprint('ciphersweet_key', $newKey);

        try {
            $rotation = $this->rotation($from, $to, $newKey);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($rotation->status === 'completed') {
            $this->components->info('This rotation is already complete.');
            $this->nextSteps();

            return self::SUCCESS;
        }

        if (! app()->isDownForMaintenance()) {
            $this->callSilently('down', ['--retry' => 60]);
            $this->components->warn('BUFFALO is in maintenance mode for the key rotation.');
        }

        $old = app(CipherSweet::class);
        $new = new CipherSweet(new StringProvider($newKey), $old->getBackend());
        /** @var array<string, array{last_id: int, rotated: int}> $progress */
        $progress = json_decode((string) $rotation->progress, true) ?: [];
        $batches = 0;
        $limit = max(0, (int) $this->option('max-batches'));

        foreach (self::MODELS as $class) {
            $model = new $class;
            $table = $model->getTable();
            $state = $progress[$class] ?? ['last_id' => 0, 'rotated' => 0];
            $remaining = DB::table($table)->where('id', '>', $state['last_id'])->count();
            $this->components->task("{$table}: {$remaining} rows to check");
            $bar = $this->output->createProgressBar($remaining);

            while (true) {
                if ($limit > 0 && $batches >= $limit) {
                    $bar->finish();
                    $this->newLine();
                    $this->components->warn("Stopped after {$batches} batches. Run the command again to continue.");

                    return self::SUCCESS;
                }

                /** @var array<int, \stdClass> $rows */
                $rows = DB::table($table)->where('id', '>', $state['last_id'])->orderBy('id')->limit((int) $this->option('batch'))
                    ->get(['id', ...$class::ENCRYPTED])->all();

                if ($rows === []) {
                    break;
                }

                DB::transaction(function () use ($rows, $class, $model, $table, $old, $new, &$state, &$progress, $rotation): void {
                    foreach ($rows as $row) {
                        $state['rotated'] += $this->rotateRow($class, $model, $table, (array) $row, $old, $new) ? 1 : 0;
                        $state['last_id'] = (int) $row->id;
                    }

                    $progress[$class] = $state;
                    DB::table('key_rotations')->where('id', $rotation->id)->update(['progress' => json_encode($progress), 'updated_at' => now()]);
                });

                $bar->advance(count($rows));
                $batches++;
            }

            $bar->finish();
            $this->newLine();
            $this->components->info("{$table}: {$state['rotated']} rows re-encrypted.");
        }

        DB::table('key_rotations')->where('id', $rotation->id)->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
        AuditLog::event('ciphersweet_key_reencrypted', 'All encrypted fields were re-encrypted under the pending CIPHERSWEET_KEY (fingerprint '.substr($to, 0, 12).'…).', properties: ['fingerprint' => substr($to, 0, 12), 'progress' => array_map(fn (array $s) => $s['rotated'], $progress)]);
        $this->components->info('Re-encryption complete.');
        $this->nextSteps();

        return self::SUCCESS;
    }

    /**
     * The rotation row for these keys, created (with the pending
     * fingerprint) on the first run.
     */
    private function rotation(string $from, string $to, string $newKey): \stdClass
    {
        if ($from === $to) {
            throw new \RuntimeException('The new key is the running key.');
        }

        $existing = DB::table('key_rotations')->where('key_name', 'ciphersweet_key')->where('to_fingerprint', $to)->first();

        if ($existing !== null) {
            if ($existing->from_fingerprint !== $from && $existing->status !== 'completed') {
                throw new \RuntimeException('This rotation was started with a different running key. Restart with the original CIPHERSWEET_KEY to resume it.');
            }

            return $existing;
        }

        if (DB::table('key_rotations')->where('key_name', 'ciphersweet_key')->where('status', 'running')->exists()) {
            throw new \RuntimeException('Another CIPHERSWEET_KEY rotation is running. Resume it with its own key file.');
        }

        KeyFingerprints::addPending('ciphersweet_key', $newKey);
        $id = DB::table('key_rotations')->insertGetId([
            'key_name' => 'ciphersweet_key', 'from_fingerprint' => $from, 'to_fingerprint' => $to,
            'status' => 'running', 'progress' => '{}', 'started_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('key_rotations')->where('id', $id)->firstOrFail();
    }

    /**
     * Re-encrypts one row and its blind indexes; false when it already uses
     * the new key.
     *
     * @param  class-string<Person|OrganizationContact>  $class
     * @param  array<string, mixed>  $row
     */
    private function rotateRow(string $class, Model $model, string $table, array $row, CipherSweet $old, CipherSweet $new): bool
    {
        $oldRow = new EncryptedRow($old, $table);
        $newRow = new EncryptedRow($new, $table);
        $class::configureCipherSweet($oldRow);
        $class::configureCipherSweet($newRow);

        if (! (new RowRotator($oldRow, $newRow))->needsReEncrypt($row)) {
            return false;
        }

        /** @var array<string, bool|float|int|string|null> $plain */
        $plain = $oldRow->decryptRow($row);
        [$encrypted, $indexes] = $newRow->prepareRowForStorage($plain);

        DB::table($table)->where('id', $row['id'])->update(array_intersect_key($encrypted, array_flip($class::ENCRYPTED)));

        foreach ($class::BLIND_INDEXES as $column => $name) {
            $key = ['indexable_type' => $model->getMorphClass(), 'indexable_id' => $row['id'], 'name' => $name];
            $value = $plain[$column] ?? null;

            if ($value === null || $value === '') {
                DB::table('blind_indexes')->where($key)->delete();

                continue;
            }

            $index = $indexes[$name];
            DB::table('blind_indexes')->upsert([...$key, 'value' => is_array($index) ? $index['value'] : $index], ['indexable_type', 'indexable_id', 'name'], ['value']);
        }

        return true;
    }

    private function nextSteps(): void
    {
        $this->line('Next:');
        $this->line('  1. Set CIPHERSWEET_KEY to the new key (secret) and restart every container; the start-up check completes the rotation.');
        $this->line('  2. php artisan up');
        $this->line('  3. Put the new key in escrow. Keep the old key in escrow while any backup made before today exists.');
    }
}
