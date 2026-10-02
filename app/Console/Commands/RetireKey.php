<?php

namespace App\Console\Commands;

use App\Support\Audit\AuditLog;
use App\Support\Keys\KeyFingerprints;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retires the recorded fingerprints of a key that has been lost (Phase
 * 8A.3), so BUFFALO can start with a replacement: the next start-up check
 * records the running key as active. Only for a key that is truly lost
 * (docs/security/key-management.md): a lost CIPHERSWEET_KEY makes the
 * encrypted fields unreadable for good. Asks for a typed confirmation.
 */
class RetireKey extends Command
{
    protected $signature = 'buffalo:keys:retire {key : app_key or ciphersweet_key} {--force : Skip the confirmation (scripts and tests)}';

    protected $description = 'Retire the fingerprint of a lost key so a replacement can be registered';

    public function handle(): int
    {
        $name = (string) $this->argument('key');

        if (! in_array($name, KeyFingerprints::NAMES, true)) {
            $this->components->error('The key must be app_key or ciphersweet_key.');

            return self::INVALID;
        }

        $this->components->warn($name === 'ciphersweet_key'
            ? 'Retiring CIPHERSWEET_KEY: data encrypted with the lost key can never be read again.'
            : 'Retiring APP_KEY: every user is signed out and must set up two-factor authentication again.');

        if (! $this->option('force') && $this->ask("Type RETIRE {$name} to continue") !== "RETIRE {$name}") {
            $this->components->info('Nothing changed.');

            return self::FAILURE;
        }

        $retired = DB::table('key_fingerprints')->where('key_name', $name)->whereIn('status', ['active', 'pending'])
            ->update(['status' => 'retired', 'retired_at' => now()]);
        DB::table('key_rotations')->where('key_name', $name)->where('status', 'running')->delete();
        AuditLog::event('key_retired', "The {$name} fingerprint was retired as lost ({$retired} fingerprints).", properties: ['key' => $name]);

        $this->components->info("Retired {$retired} fingerprint(s). Restart BUFFALO with the replacement key; the start-up check records it.");

        return self::SUCCESS;
    }
}
