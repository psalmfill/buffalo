<?php

use Illuminate\Support\Str;

/**
 * Resolve a path to its canonical form even if it does not exist yet (the
 * evidence volume is only mounted inside the containers).
 */
function canonicalPath(string $path): string
{
    $real = realpath($path);

    return rtrim(str_replace('\\', '/', $real !== false ? $real : $path), '/');
}

test('the evidence disk is private and never served', function () {
    $disk = config('filesystems.disks.evidence');

    expect($disk)->toBeArray()
        ->and($disk['driver'])->toBe('local')
        ->and($disk['visibility'])->toBe('private')
        ->and($disk['serve'])->toBeFalse()
        ->and($disk)->not->toHaveKey('url');
});

test('the evidence disk root is outside public/ and the application tree', function () {
    $root = canonicalPath(config('filesystems.disks.evidence.root'));

    expect(Str::startsWith($root.'/', canonicalPath(public_path()).'/'))->toBeFalse()
        ->and(Str::startsWith($root.'/', canonicalPath(base_path()).'/'))->toBeFalse();
});

test('no storage:link entry points at the evidence disk', function () {
    $root = canonicalPath(config('filesystems.disks.evidence.root'));

    foreach (config('filesystems.links', []) as $link => $target) {
        expect(canonicalPath($target))->not->toBe($root);
    }
});

test('no symlink under public/ points at the evidence disk', function () {
    $root = canonicalPath(config('filesystems.disks.evidence.root'));

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(public_path(), FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    $offending = [];

    foreach ($iterator as $file) {
        if (! $file->isLink()) {
            continue;
        }

        $target = canonicalPath((string) $file->getRealPath() ?: (string) readlink($file->getPathname()));

        if (Str::startsWith($target.'/', $root.'/')) {
            $offending[] = $file->getPathname();
        }
    }

    expect($offending)->toBeEmpty();
});
