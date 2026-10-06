<?php

use App\Support\Malware\ClamdScanner;
use App\Support\Malware\ScannerUnavailable;
use App\Support\Malware\ScannerVersion;
use App\Support\Malware\ScanResult;
use Tests\Support\Malware\FakeMalwareScanner;

/*
| Phase 8B.4: the clamd client against the real clamav service. Skipped
| when clamd is not reachable, except where BUFFALO_REQUIRE_CLAMAV=true
| (make ci and GitHub CI), where it must pass.
*/

beforeEach(function () {
    try {
        (new ClamdScanner)->version();
    } catch (ScannerUnavailable) {
        if (filter_var(env('BUFFALO_REQUIRE_CLAMAV', false), FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('clamd is required (BUFFALO_REQUIRE_CLAMAV=true) but unreachable at '.config('buffalo.malware.host').':'.config('buffalo.malware.port'));
        }
        $this->markTestSkipped('clamd is not reachable.');
    }
});

function scanString(string $content): ScanResult
{
    $path = tempnam(sys_get_temp_dir(), 'scan');
    file_put_contents($path, $content);

    try {
        return (new ClamdScanner)->scan($path);
    } finally {
        unlink($path);
    }
}

test('clamd finds the EICAR test file and passes clean content', function () {
    $infected = scanString(FakeMalwareScanner::eicar());
    expect($infected->infected)->toBeTrue()->and($infected->signature)->toContain('Eicar');

    expect(scanString('An ordinary incident note.')->infected)->toBeFalse();
});

test('files larger than one chunk stream through', function () {
    $path = tempnam(sys_get_temp_dir(), 'scan');
    file_put_contents($path, str_repeat('A', 3 * 1024 * 1024 + 17));

    expect((new ClamdScanner)->scan($path)->infected)->toBeFalse();
    unlink($path);
});

test('clamd reports its signature version and date', function () {
    $version = (new ClamdScanner)->version();

    expect($version->engine)->toStartWith('ClamAV ')
        ->and($version->signatures)->toBeInt()
        ->and($version->signaturesDate)->not->toBeNull();
});

test('an unreachable clamd is reported as unavailable', function () {
    config(['buffalo.malware.port' => 1, 'buffalo.malware.connect_timeout' => 1]);

    expect(fn () => (new ClamdScanner)->version())->toThrow(ScannerUnavailable::class);
});

test('VERSION replies are parsed', function () {
    $version = ScannerVersion::parse("ClamAV 1.5.4/28137/Mon Sep 28 06:24:12 2026\n");

    expect($version->engine)->toBe('ClamAV 1.5.4')
        ->and($version->signatures)->toBe(28137)
        ->and($version->signaturesDate?->toDateTimeString())->toBe('2026-09-28 06:24:12')
        ->and(ScannerVersion::parse('ClamAV 1.5.4')->signaturesDate)->toBeNull();
});
