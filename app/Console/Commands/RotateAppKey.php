<?php

namespace App\Console\Commands;

use App\Support\Audit\AuditLog;
use App\Support\Keys\KeyFingerprints;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * APP_KEY rotation (Phase 8A.3), with Laravel's previous-keys mechanism:
 *
 * 1. `--prepare --output=FILE`: creates a new key in FILE (mode 0600) and
 *    records its fingerprint as pending.
 * 2. The operator sets APP_KEY to the new key and APP_PREVIOUS_KEYS to the
 *    old one, and restarts. The start-up check sees the pending key with
 *    the active one among the previous keys, and completes the rotation.
 *    Sessions, cookies, signed links and stored values encrypted under the
 *    old key keep working (Laravel tries the previous keys).
 * 3. `--reencrypt`: re-encrypts data stored with Laravel encryption
 *    (users' two-factor secrets and recovery codes) under the new key.
 * 4. After the session lifetime, the operator removes APP_PREVIOUS_KEYS and
 *    restarts. Hit tokens and signed links issued before the rotation have
 *    expired by then.
 *
 * Without options it prints this procedure.
 */
class RotateAppKey extends Command
{
    protected $signature = 'buffalo:keys:rotate-app-key
        {--prepare : Create the new key and record its fingerprint as pending}
        {--output= : File for the new key (with --prepare; created with mode 0600)}
        {--reencrypt : Re-encrypt stored values under the current APP_KEY}
        {--batch=200 : Rows per batch for --reencrypt}';

    protected $description = 'Rotate APP_KEY: prepare a new key, then re-encrypt stored values (see docs/security/key-management.md)';

    /**
     * Columns encrypted with Laravel's encrypter: Fortify two-factor
     * authentication, and a person merge's prior values (Phase 10A.2).
     */
    private const ENCRYPTED_COLUMNS = [
        'users' => ['two_factor_secret', 'two_factor_recovery_codes'],
        'person_merges' => ['survivor_prior_values'],
    ];

    public function handle(): int
    {
        return match (true) {
            (bool) $this->option('prepare') => $this->prepare(),
            (bool) $this->option('reencrypt') => $this->reencrypt(),
            default => $this->procedure(),
        };
    }

    private function prepare(): int
    {
        $output = (string) $this->option('output');

        if ($output === '' || file_exists($output)) {
            $this->components->error('Give --output=FILE: a new file for the key (it is never printed).');

            return self::INVALID;
        }

        $key = 'base64:'.base64_encode(random_bytes(32));

        try {
            $fingerprint = KeyFingerprints::addPending('app_key', $key);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $previous = umask(0077);
        file_put_contents($output, $key."\n");
        umask($previous);
        chmod($output, 0600);

        $this->components->info("New APP_KEY written to {$output} (fingerprint {$fingerprint}).");
        $this->procedure(from: 2);

        return self::SUCCESS;
    }

    private function reencrypt(): int
    {
        $stored = KeyFingerprints::stored('app_key');

        if ($stored['active'] !== KeyFingerprints::fingerprint('app_key', KeyFingerprints::runningKey('app_key')) || $stored['pending'] !== null) {
            $this->components->error('Finish step 2 first: the running APP_KEY must be the active key (restart with the new key and APP_PREVIOUS_KEYS).');

            return self::FAILURE;
        }

        $total = 0;

        foreach (self::ENCRYPTED_COLUMNS as $table => $columns) {
            $query = DB::table($table)->where(fn ($q) => collect($columns)->each(fn (string $c) => $q->orWhereNotNull($c)))->orderBy('id');

            $query->chunkById((int) $this->option('batch'), function ($rows) use ($table, $columns, &$total): void {
                DB::transaction(function () use ($rows, $table, $columns, &$total): void {
                    // person_merges allows replacing survivor_prior_values only
                    // during a rotation (its trigger reads this setting).
                    DB::statement("SELECT set_config('buffalo.key_rotation', 'on', true)");

                    foreach ($rows as $row) {
                        $values = [];
                        foreach ($columns as $column) {
                            if ($row->{$column} !== null) {
                                // Decrypts with the current or a previous key; encrypts with the current one.
                                $values[$column] = Crypt::encrypt(Crypt::decrypt($row->{$column}));
                            }
                        }
                        DB::table($table)->where('id', $row->id)->update($values);
                        $total++;
                    }
                });
            });
        }

        AuditLog::event('app_key_reencrypted', "Values encrypted with APP_KEY were re-encrypted under the current key ({$total} rows).", properties: ['rows' => $total]);
        $this->components->info("{$total} rows re-encrypted under the current APP_KEY.");
        $this->line('  Next: after the session lifetime ('.config('session.lifetime').' minutes), remove APP_PREVIOUS_KEYS and restart.');

        return self::SUCCESS;
    }

    private function procedure(int $from = 1): int
    {
        $steps = [
            1 => 'php artisan buffalo:keys:rotate-app-key --prepare --output=/secure/path/app_key.new',
            2 => 'Set APP_KEY to the new key and APP_PREVIOUS_KEYS to the old key (secrets), then restart every container. The start-up check completes the rotation.',
            3 => 'php artisan buffalo:keys:rotate-app-key --reencrypt',
            4 => 'After the session lifetime, remove APP_PREVIOUS_KEYS and restart. Store the new key in escrow (docs/security/key-management.md).',
        ];

        $this->line('APP_KEY rotation:');
        foreach ($steps as $number => $step) {
            if ($number >= $from) {
                $this->line("  {$number}. {$step}");
            }
        }

        return self::SUCCESS;
    }
}
