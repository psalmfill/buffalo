<?php

use App\Actions\Evidence\StoreEvidence;
use App\Enums\Classification;
use App\Models\Incident;
use App\Models\IncidentEvidence;
use App\Support\Evidence\EvidenceFileInspector;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Keys\KeyFingerprints;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Incidents\EvidenceFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/*
| Phase 8C.3: buffalo:restore:verify, the post-restore checks.
*/

beforeEach(function () {
    Storage::fake('evidence');
    Storage::fake('quarantine');
    CrossFixtures::system();
    KeyFingerprints::verify();
    $this->world = JurisdictionWorld::build();
});

function manifestFor(array $overrides = []): string
{
    $keys = [];
    foreach (KeyFingerprints::NAMES as $name) {
        $keys[$name] = KeyFingerprints::fingerprint($name, KeyFingerprints::runningKey($name));
    }

    return (string) json_encode(['backup' => '20261006-010000F', 'key_fingerprints' => [...$keys, ...$overrides]]);
}

function verifyRestore(array $options = []): array
{
    $code = Artisan::call('buffalo:restore:verify', ['--json' => true, ...$options]);

    return [$code, json_decode(Artisan::output(), true)];
}

function storeTestEvidence(Incident $incident): IncidentEvidence
{
    $chief = test()->world->users['lga_chief'];
    $file = EvidenceFixtures::text();
    $detected = app(EvidenceFileInspector::class)->inspect((string) $file->getRealPath(), $file->getClientOriginalName());

    $evidence = app(JurisdictionContext::class)->actingAs($chief, fn () => app(StoreEvidence::class)(
        $incident, $file, (array) $detected, ['title' => 'Note', 'description' => null, 'classification' => Classification::Restricted], $chief,
    ));
    CrossFixtures::system();

    return $evidence;
}

test('matching keys and an intact restore verify', function () {
    CrossFixtures::person($this->world->lgas['A1a'], ['nin' => '12345678901', 'phone' => '08031234567']);
    storeTestEvidence(Incident::factory()->in($this->world->lgas['A1a'])->by($this->world->users['lga_chief'])->create());

    [$code, $result] = verifyRestore(['--manifest-json' => manifestFor()]);

    expect($code)->toBe(0)
        ->and($result['ok'])->toBeTrue()
        ->and(array_keys($result['checks']))->toBe(['keys', 'row_counts', 'persons', 'evidence', 'audit_chains', 'migrations'])
        ->and($result['checks']['persons']['detail'])->toContain('found by blind index, 0 failed')
        ->and(json_encode($result))->not->toContain('12345678901')->not->toContain('08031234567');
});

test('a wrong CIPHERSWEET_KEY or a missing manifest is refused before anything else', function (?string $manifest) {
    [$code, $result] = verifyRestore($manifest === null ? [] : ['--manifest-json' => $manifest]);

    expect($code)->toBe(1)
        ->and($result['ok'])->toBeFalse()
        ->and(array_keys($result['checks']))->toBe(['keys'])
        ->and($result['checks']['keys']['detail'])->toContain('Refusing to continue');
})->with([
    'wrong ciphersweet key' => [fn () => manifestFor(['ciphersweet_key' => str_repeat('a', 64)])],
    'wrong app key' => [fn () => manifestFor(['app_key' => str_repeat('b', 64)])],
    'no manifest' => [null],
]);

test('an altered evidence file fails; one already reported before the backup is listed, not failed', function () {
    $incident = Incident::factory()->in($this->world->lgas['A1a'])->by($this->world->users['lga_chief'])->create();
    $evidence = storeTestEvidence($incident);
    file_put_contents((string) $evidence->original()?->getPath(), 'altered');

    [$code, $result] = verifyRestore(['--manifest-json' => manifestFor(), '--all-evidence' => true]);
    expect($code)->toBe(1)->and($result['checks']['evidence']['detail'])->toContain('1 missing or altered');

    // The integrity check had already failed (and was reported) before the backup.
    activity('audit')->performedOn($evidence)->event('integrity_failure')->log('Integrity check failed');

    [$code, $result] = verifyRestore(['--manifest-json' => manifestFor(), '--all-evidence' => true]);
    expect($code)->toBe(0)->and($result['checks']['evidence']['detail'])->toContain('1 with an integrity failure already reported');
});
