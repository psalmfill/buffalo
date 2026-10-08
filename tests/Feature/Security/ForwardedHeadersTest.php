<?php

use App\Models\LoginHistory;
use App\Models\State;
use App\Models\User;

/*
| The application trusts no proxies (UAT demo, docs/operations/demo-hosting.md):
| only nginx decides what the client's address and scheme are, and passes
| them as REMOTE_ADDR and HTTPS. Forwarded headers a client sends are ignored.
*/

test('a client\'s X-Forwarded-For is never used as its address', function () {
    $user = User::factory()->stateDirector(State::factory()->create())->create();

    $this->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Real-IP' => '203.0.113.9'])
        ->post('/login', ['email' => $user->email, 'password' => 'not the password']);

    $entry = LoginHistory::query()->latest('id')->firstOrFail();
    expect($entry->ip_address)->toBe('127.0.0.1');
});

test('a client\'s X-Forwarded-Proto and X-Forwarded-Host are ignored', function () {
    $response = $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Port' => '443'])
        ->get('http://demo.buffalo.test/');

    expect((string) $response->headers->get('Location'))->toStartWith('http://demo.buffalo.test/');
});
