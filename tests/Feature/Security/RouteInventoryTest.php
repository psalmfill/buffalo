<?php

use App\Console\Commands\RouteInventoryCommand;
use App\Support\Security\RouteInventory;

/*
| Phase 8B.6: every registered route is authenticated (or explicitly
| public) and has a detected authorisation check (or an explicit,
| justified self-service entry). How detection works:
| App\Support\Security\RouteInventory. JurisdictionMatrixTest proves the
| checks hold for each role, inside and outside its jurisdiction.
*/

function inventory(): array
{
    return app(RouteInventory::class)->rows();
}

test('every route is authenticated or explicitly public', function () {
    $unauthenticated = collect(inventory())->where('authentication', 'none')
        ->map(fn (array $row) => "{$row['method']} {$row['uri']}")->values()->all();

    expect($unauthenticated)->toBe([]);
});

test('every route has a detected authorisation check or a justified allow-list entry', function () {
    $unauthorised = collect(inventory())->whereNull('authorisation')
        ->map(fn (array $row) => "{$row['method']} {$row['uri']} ({$row['action']})")->values()->all();

    expect($unauthorised)->toBe([]);
});

test('allow-list entries all match a registered route and are used', function () {
    $rows = collect(inventory());
    $keys = $rows->map(fn (array $row) => "{$row['method']} {$row['uri']}")->all();

    foreach ([...array_keys(RouteInventory::PUBLIC), ...array_keys(RouteInventory::REDIRECTS)] as $key) {
        expect($keys)->toContain($key);
    }
    foreach (array_keys(RouteInventory::SELF_SERVICE) as $name) {
        $row = $rows->firstWhere('name', $name);
        expect($row)->not->toBeNull("Self-service route {$name} is not registered")
            ->and($row['authentication'])->toBe('auth', "Self-service route {$name} must require sign-in");
    }
    // Public entries never sit behind sign-in, and nothing public carries data routes.
    expect($rows->where('authentication', 'public')->count())->toBe(count(RouteInventory::PUBLIC));
});

test('no unauthenticated file-serving or developer routes are registered', function () {
    $uris = array_column(inventory(), 'uri');

    expect(array_filter($uris, fn (string $uri) => str_starts_with($uri, 'storage/') || str_starts_with($uri, '_inertia/') || str_starts_with($uri, '_debugbar') || str_starts_with($uri, 'telescope')))->toBe([]);
});

test('docs/security/route-inventory.md is up to date', function () {
    expect($this->artisan('buffalo:security:route-inventory', ['--check' => true])->run())->toBe(0);
    expect((string) file_get_contents(base_path(RouteInventoryCommand::PATH)))->toBe(RouteInventory::markdown(inventory()));
});
