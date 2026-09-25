<?php

use App\Models\User;

test('the root URL sends guests to the login page', function () {
    $this->get('/')->assertRedirect(route('login'));
});

test('the root URL sends authenticated users to the dashboard', function () {
    $this->actingAs(User::factory()->directorGeneral()->create())
        ->get('/')
        ->assertRedirect(route('dashboard'));
});
