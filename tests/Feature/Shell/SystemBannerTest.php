<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the banner is shared on sign-in, app and error pages when configured', function () {
    config(['buffalo.banner.text' => 'RESTRICTED — Authorised users only. All activity is logged.', 'buffalo.banner.level' => 'restricted']);
    $banner = ['text' => 'RESTRICTED — Authorised users only. All activity is logged.', 'level' => 'restricted'];

    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('app.banner', $banner));
    $this->get('/no-such-page')->assertInertia(fn (Assert $page) => $page->where('app.banner', $banner));

    $this->actingAs(User::factory()->directorGeneral()->create())->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('app.banner', $banner));
});

test('there is no banner when no text is configured', function (?string $text) {
    config(['buffalo.banner.text' => $text, 'buffalo.banner.level' => 'secret']);

    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('app.banner', null));

    $this->actingAs(User::factory()->directorGeneral()->create())->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('app.banner', null));
})->with([null, '', '   ']);

test('the banner level is normalised, and unknown levels fall back to info', function (string $level, string $expected) {
    config(['buffalo.banner.text' => 'Notice', 'buffalo.banner.level' => $level]);

    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('app.banner.level', $expected));
})->with([
    ['info', 'info'],
    ['restricted', 'restricted'],
    ['CONFIDENTIAL', 'confidential'],
    [' secret ', 'secret'],
    ['top-secret', 'info'],
]);
