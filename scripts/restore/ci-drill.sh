#!/usr/bin/env bash
#
# Restore drill at small scale, for CI (Phase 8C.3). Expects a fresh stack
# (postgres, valkey, app, pgbackrest, backup) started from this checkout
# with migrations and seeders run. Adds a little fixture data (persons with
# encrypted fields, an evidence file), waits for the first database and
# evidence backups, then runs the drill twice: a normal restore (must
# verify) and a negative test with a wrong CIPHERSWEET_KEY (must be refused).
#
# Never run against a stack holding real data: it inserts test records.
set -euo pipefail
cd "$(dirname "$0")/../.."

say() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }

say "Fixture data"
docker compose exec -T app php artisan tinker --execute '
    if (app()->environment("production")) { throw new RuntimeException("Refused in production."); }
    app(App\Support\Jurisdiction\JurisdictionContext::class)->system();
    $lga = App\Models\Lga::query()->firstOrFail();
    foreach (range(1, 5) as $i) {
        App\Models\Person::factory()->in($lga)->create(["nin" => "1000000000".$i, "phone" => "0803000000".$i]);
    }
    $user = App\Models\User::factory()->lgaChief($lga)->create();
    $incident = App\Models\Incident::factory()->in($lga)->by($user)->create();
    $file = Illuminate\Http\UploadedFile::fake()->createWithContent("drill.txt", "Restore drill fixture ".now());
    $detected = app(App\Support\Evidence\EvidenceFileInspector::class)->inspect((string) $file->getRealPath(), "drill.txt");
    app(App\Support\Jurisdiction\JurisdictionContext::class)->actingAs($user, fn () => app(App\Actions\Evidence\StoreEvidence::class)($incident, $file, (array) $detected, ["title" => "Drill", "description" => null, "classification" => App\Enums\Classification::Restricted], $user));
    echo "fixtures ready\n";'

say "Fresh backups of the fixture data"
docker compose exec -T postgres psql -U "${DB_ADMIN_USERNAME:-buffalo}" -d "${DB_DATABASE:-buffalo}" -Atc 'SELECT pg_switch_wal()' >/dev/null
docker compose exec -T pgbackrest backup-entrypoint.sh pgbackrest-scheduler.sh now diff
docker compose exec -T backup restic-scheduler.sh now backup

say "Drill: normal restore"
scripts/restore/drill.sh --no-report --all-evidence

say "Drill: wrong CIPHERSWEET_KEY must be refused"
scripts/restore/drill.sh --no-report --wrong-keys

say "Restore drills passed."
