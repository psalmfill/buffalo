<?php

test('the health check route responds with 200', function () {
    $this->get('/up')->assertOk();
});

test('the health check loads nothing from the internet and starts no session', function () {
    $response = $this->get('/up')->assertOk();

    expect($response->getContent())->toBe('up')->not->toContain('http')
        ->and($response->headers->getCookies())->toBe([]);
    $this->getJson('/up')->assertOk()->assertExactJson(['status' => 'up']);
});
