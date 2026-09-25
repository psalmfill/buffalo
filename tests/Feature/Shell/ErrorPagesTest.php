<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::get('/_test/server-error', fn () => throw new RuntimeException('database password is hunter2'));
        Route::get('/_test/expired', fn () => throw new TokenMismatchException);
        Route::get('/_test/maintenance', fn () => abort(503));
    });
});

test('an unknown URL renders the BUFFALO 404 page', function () {
    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/status')
            ->where('status', 404)
            ->where('signedIn', false)
            ->where('app.name', config('app.name')));
});

test('a user outside the record\'s jurisdiction gets 404, not 403, and the page reveals nothing', function () {
    $world = JurisdictionWorld::build();
    $outsider = User::factory()->lgaChief($world->lgas['B1'])->create();

    $response = $this->actingAs($world->users['state_director'])
        ->get("/users/{$outsider->ulid}")
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/status')
            ->where('status', 404)
            ->where('signedIn', true));

    expect($response->getContent())->not->toContain($outsider->name)
        ->not->toContain($outsider->email)
        ->not->toContain('jurisdiction');
});

test('a forbidden page renders the 403 page without the reason', function () {
    $world = JurisdictionWorld::build();

    $response = $this->actingAs($world->users['lga_chief'])->get('/users')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('errors/status')->where('status', 403));

    expect($response->getContent())->not->toContain('This action is unauthorized');
});

test('an expired session renders the 419 page', function () {
    $this->get('/_test/expired')
        ->assertStatus(419)
        ->assertInertia(fn (Assert $page) => $page->component('errors/status')->where('status', 419));
});

test('server errors render the 500 page without details when debug is off', function () {
    config(['app.debug' => false]);

    $response = $this->get('/_test/server-error')
        ->assertStatus(500)
        ->assertInertia(fn (Assert $page) => $page->component('errors/status')->where('status', 500));

    expect($response->getContent())->not->toContain('hunter2');
});

test('server errors keep the debug page when debug is on', function () {
    config(['app.debug' => true]);

    $this->get('/_test/server-error')->assertStatus(500)->assertSee('hunter2');
});

test('maintenance renders the 503 page', function () {
    $this->get('/_test/maintenance')
        ->assertStatus(503)
        ->assertInertia(fn (Assert $page) => $page->component('errors/status')->where('status', 503));
});

test('Inertia visits get the error page as an Inertia response', function () {
    $version = app(HandleInertiaRequests::class)->version(request());

    $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version])
        ->get('/no-such-page')
        ->assertNotFound()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'errors/status');
});

test('JSON callers keep JSON errors', function () {
    $world = JurisdictionWorld::build();

    $this->actingAs($world->users['lga_chief'])->getJson('/users')->assertForbidden()->assertJsonStructure(['message']);
    $this->getJson('/no-such-page')->assertNotFound()->assertJsonStructure(['message']);
});
