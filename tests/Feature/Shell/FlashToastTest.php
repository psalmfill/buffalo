<?php

use App\Http\Middleware\HandleInertiaRequests;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/*
| Toasts flashed with redirect()->with('toast', ...) reach the next page as
| Inertia flash data, which the client shows (use-flash-toast.ts). Found by
| the Phase 11 browser check: Inertia 3 does not pass session flashes on.
*/

test('a toast flashed on a redirect reaches the next page once', function () {
    CrossFixtures::system();
    $world = JurisdictionWorld::build();
    $dg = $world->users['director_general'];
    $client = CrossFixtures::signIn($dg);

    $client->delete('/system/theme')->assertRedirect('/system/theme');

    $page = $client->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())])
        ->get('/system/theme');
    $page->assertOk();
    expect($page->json('flash.toast'))->toBe(['type' => 'success', 'message' => 'Theme colours reset to defaults.']);

    // Shown once: the next page carries no toast.
    $again = $client->withHeaders(['X-Inertia' => 'true'])->get('/system/theme');
    expect($again->json('flash'))->toBeNull();
});
