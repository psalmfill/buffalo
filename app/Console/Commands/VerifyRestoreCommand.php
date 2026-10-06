<?php

namespace App\Console\Commands;

use App\Models\IncidentEvidence;
use App\Models\Person;
use App\Support\Keys\KeyFingerprints;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Post-restore checks (Phase 8C.3), run by scripts/restore/verify-restore.sh
 * and the restore drill against a restored database and evidence volume.
 *
 * 1. Keys: the running APP_KEY and CIPHERSWEET_KEY must match the backup
 *    manifest's fingerprints (and the restored database's active ones).
 *    Anything else stops here: data encrypted with other keys would be
 *    unreadable, and writing with the wrong key would corrupt it.
 * 2. Row counts of key tables.
 * 3. A sample of persons: encrypted fields decrypt, and a blind-index
 *    search for each value finds the same person.
 * 4. A sample of evidence originals (all with --all-evidence) match their
 *    recorded SHA-256 (infected originals are checked on the quarantine
 *    disk). Items whose last integrity check had already failed before the
 *    backup are listed, not failed: the restore reproduced them faithfully.
 * 5. buffalo:audit:verify passes for every chain.
 * 6. No migration is pending.
 *
 * Prints counts, hashes and results only: never decrypted values or names.
 * Runs in the console's system context (no jurisdiction scope).
 */
class VerifyRestoreCommand extends Command
{
    protected $signature = 'buffalo:restore:verify
        {--manifest= : Backup manifest JSON file (key fingerprints of the restored backup)}
        {--manifest-json= : The manifest as a JSON string, instead of a file}
        {--sample=25 : Persons and evidence files to sample}
        {--all-evidence : Check every evidence original}
        {--json : Print the results as JSON (for the drill report)}';

    protected $description = 'Verify a restored database and evidence volume';

    private const TABLES = [
        'users', 'persons', 'persons_of_interest', 'communities', 'incidents', 'incident_evidence', 'flashpoints',
        'threat_reports', 'organizations', 'organization_sites', 'agency_formations', 'coverage_reports',
        'activity_log', 'record_access_logs', 'login_histories', 'audit_anchors',
    ];

    /** @var array<string, array{ok: bool, detail: string}> */
    private array $results = [];

    public function handle(Migrator $migrator): int
    {
        if (! $this->checkKeys()) {
            return $this->finish();
        }

        $this->rowCounts();
        $this->persons((int) $this->option('sample'));
        $this->evidence((int) $this->option('sample'), (bool) $this->option('all-evidence'));
        $this->auditChains();
        $this->migrations($migrator);

        return $this->finish();
    }

    private function checkKeys(): bool
    {
        $manifest = $this->manifest();
        if ($manifest === null) {
            return $this->result('keys', false, 'No readable backup manifest (--manifest or --manifest-json): the keys cannot be confirmed. Refusing to continue.');
        }

        $problems = [];
        foreach (KeyFingerprints::NAMES as $name) {
            try {
                $running = KeyFingerprints::fingerprint($name, KeyFingerprints::runningKey($name));
            } catch (Throwable) {
                $problems[] = "{$name} is not configured";

                continue;
            }
            $expected = $manifest['key_fingerprints'][$name] ?? null;
            $stored = DB::table('key_fingerprints')->where('key_name', $name)->where('status', 'active')->value('fingerprint');

            if ($expected === null) {
                $problems[] = "the manifest has no {$name} fingerprint";
            } elseif (! hash_equals((string) $expected, $running)) {
                $problems[] = "the running {$name} does not match the backup";
            } elseif ($stored !== null && ! hash_equals((string) $stored, $running)) {
                $problems[] = "the running {$name} does not match the restored database";
            }
        }

        if ($problems !== []) {
            return $this->result('keys', false, 'Key fingerprints do not match: '.implode('; ', $problems).'. Refusing to continue: supply the escrowed keys of this backup.');
        }

        return $this->result('keys', true, 'APP_KEY and CIPHERSWEET_KEY match backup '.($manifest['backup'] ?? '?'));
    }

    /** @return array{backup?: string, key_fingerprints?: array<string, string>}|null */
    private function manifest(): ?array
    {
        $json = $this->option('manifest-json');
        if (! is_string($json) || $json === '') {
            $file = $this->option('manifest');
            $json = is_string($file) && is_file($file) ? (string) file_get_contents($file) : null;
        }
        $data = is_string($json) ? json_decode($json, true) : null;

        return is_array($data) && is_array($data['key_fingerprints'] ?? null) ? $data : null;
    }

    private function rowCounts(): void
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[] = $table.' '.number_format((int) DB::table($table)->count());
        }
        $this->result('row_counts', true, implode(', ', $counts));
    }

    private function persons(int $sample): void
    {
        $checked = 0;
        $failures = 0;

        $persons = Person::query()->withoutGlobalScopes()->whereNotNull('nin')->inRandomOrder()->limit($sample)->get();
        foreach ($persons as $person) {
            try {
                foreach (array_keys(Person::BLIND_INDEXES) as $column) {
                    $value = $person->getAttribute($column);
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $value = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
                    $found = Person::query()->withoutGlobalScopes()->whereBlind($column, Person::BLIND_INDEXES[$column], $value)->whereKey($person->getKey())->exists();
                    $found ? $checked++ : $failures++;
                }
            } catch (Throwable) {
                $failures++; // Did not decrypt.
            }
        }

        $this->result('persons', $failures === 0, $persons->isEmpty()
            ? 'No persons with encrypted fields to sample.'
            : "{$persons->count()} persons sampled: {$checked} encrypted values decrypted and found by blind index, {$failures} failed.");
    }

    private function evidence(int $sample, bool $all): void
    {
        $query = IncidentEvidence::query()->withoutGlobalScopes()->with('media');
        $items = $all ? $query->orderBy('id')->get() : $query->inRandomOrder()->limit($sample)->get();
        $bad = 0;
        $known = 0;

        foreach ($items as $evidence) {
            $path = $evidence->scan_status->value === 'infected'
                ? Storage::disk('quarantine')->path('evidence/'.$evidence->ulid.'/original.quarantined')
                : $evidence->original()?->getPath();
            $hash = $path !== null && is_file($path) ? hash_file('sha256', $path) : null;
            if ($hash === false || $hash === null || ! hash_equals($evidence->sha256, $hash)) {
                // An item whose latest integrity check already failed (and was
                // reported then) was restored as it was: not a restore fault.
                self::knownIntegrityFailure($evidence) ? $known++ : $bad++;
            }
        }

        $scope = $all ? 'all' : 'a sample of';
        $this->result('evidence', $bad === 0, $items->isEmpty()
            ? 'No evidence to check.'
            : "{$scope} {$items->count()} evidence originals checked: ".($items->count() - $bad - $known)." match their SHA-256, {$bad} missing or altered"
                .($known > 0 ? ", {$known} with an integrity failure already reported before the backup" : '').'.');
    }

    private static function knownIntegrityFailure(IncidentEvidence $evidence): bool
    {
        return DB::table('activity_log')
            ->where('subject_type', $evidence->getMorphClass())->where('subject_id', $evidence->getKey())
            ->whereIn('event', ['integrity_failure', 'integrity_verified'])
            ->orderByDesc('id')->value('event') === 'integrity_failure';
    }

    private function auditChains(): void
    {
        // Its own buffer: Artisan::call() would replace this command's output.
        $buffer = new BufferedOutput;
        $code = $this->runCommand('buffalo:audit:verify', [], $buffer);
        $this->result('audit_chains', $code === 0, $code === 0 ? 'Every audit chain verifies.' : 'buffalo:audit:verify failed: '.trim($buffer->fetch()));
    }

    private function migrations(Migrator $migrator): void
    {
        $repository = $migrator->getRepository();
        $ran = $repository->repositoryExists() ? $repository->getRan() : [];
        $files = array_keys($migrator->getMigrationFiles([database_path('migrations')]));
        $pending = array_values(array_diff($files, $ran));

        $this->result('migrations', $pending === [], $pending === [] ? 'All migrations have run.' : count($pending).' pending: '.implode(', ', array_slice($pending, 0, 5)));
    }

    private function result(string $check, bool $ok, string $detail): bool
    {
        $this->results[$check] = ['ok' => $ok, 'detail' => $detail];

        return $ok;
    }

    private function finish(): int
    {
        $ok = collect($this->results)->every(fn (array $r) => $r['ok']);

        if ($this->option('json')) {
            $this->line((string) json_encode(['ok' => $ok, 'checks' => $this->results], JSON_PRETTY_PRINT));
        } else {
            foreach ($this->results as $check => $result) {
                $this->components->twoColumnDetail(($result['ok'] ? '<fg=green>✓</>' : '<fg=red>✗</>').' '.str_replace('_', ' ', $check), '');
                $this->line('    '.$result['detail']);
            }
            $ok ? $this->components->info('The restore verifies.') : $this->components->error('The restore does NOT verify.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
