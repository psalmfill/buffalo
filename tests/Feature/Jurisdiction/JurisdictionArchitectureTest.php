<?php

use Illuminate\Support\Facades\File;

/**
 * The jurisdiction scope may only be removed inside App\Support\Jurisdiction
 * (e.g. AuthenticationUserProvider). Everything else must go through
 * JurisdictionContext::runAsSystem(), which is audited.
 */
test('no code outside the jurisdiction support namespace bypasses JurisdictionScope', function () {
    $allowed = realpath(base_path('app/Support/Jurisdiction'));
    $violations = [];

    $patterns = [
        '/withoutGlobalScope\s*\(\s*[^)]*JurisdictionScope/' => 'removes JurisdictionScope',
        '/withoutGlobalScopes\s*\(/' => 'removes all global scopes (including JurisdictionScope)',
        '/JurisdictionContext::class\)\s*->\s*system\s*\(|\$[a-zA-Z_]*[cC]ontext\s*->\s*system\s*\(/' => 'switches the jurisdiction context to system mode (use runAsSystem())',
    ];

    foreach (['app', 'bootstrap', 'config', 'database', 'routes'] as $directory) {
        foreach (File::allFiles(base_path($directory)) as $file) {
            if ($file->getExtension() !== 'php' || str_starts_with((string) $file->getRealPath(), (string) $allowed)) {
                continue;
            }

            $contents = $file->getContents();

            foreach ($patterns as $pattern => $description) {
                if (preg_match($pattern, $contents) === 1) {
                    $violations[] = str_replace(base_path().'/', '', (string) $file->getRealPath()).": {$description}";
                }
            }
        }
    }

    expect($violations)->toBe([]);
});

test('the architecture check detects a bypass', function () {
    $sample = '<?php Record::query()->withoutGlobalScope(JurisdictionScope::class)->get();';

    expect(preg_match('/withoutGlobalScope\s*\(\s*[^)]*JurisdictionScope/', $sample))->toBe(1);
});

arch('jurisdiction support classes are final or contracts')
    ->expect('App\Support\Jurisdiction')
    ->classes()
    ->toBeFinal()
    ->ignoring('App\Support\Jurisdiction\Contracts');
