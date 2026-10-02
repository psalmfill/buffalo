<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CrossJurisdiction\CrossFixtures;

/*
| Phase 8A.6: after signing out, the browser keeps nothing it could show
| again with Back.
*/

test('pages seen while signed in are never stored by the browser, and history is encrypted', function () {
    $user = User::factory()->directorGeneral()->create();

    $response = CrossFixtures::signIn($user)->get('/dashboard');
    $response->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->viewData('page')['encryptHistory'] ?? false)->toBeTrue();
});

test('the sign-in page clears the browser history of the previous session', function () {
    $user = User::factory()->directorGeneral()->create();
    CrossFixtures::signIn($user)->post('/logout')->assertRedirect();

    $this->get('/login')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/login'))
        ->assertViewHas('page', fn (array $page) => ($page['clearHistory'] ?? false) === true);

    // The dashboard is no longer reachable.
    $this->get('/dashboard')->assertRedirect('/login');
});
