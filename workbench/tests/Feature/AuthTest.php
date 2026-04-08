<?php

declare(strict_types=1);

use App\Models\User;
use WorkOS\AuthKit\WorkOS;

test('guest is redirected to login page', function () {
    $this->get('/dashboard')
        ->assertRedirect('/auth/login');
});

test('login route redirects to workos', function () {
    config(['workos.client_id' => 'test_client_id']);

    $this->get('/auth/login')
        ->assertRedirect();
});

// Converted from: $this->actingAs($user, 'workos')
// WorkOS::actingAs() activates the fake and logs in the user — no real API calls.
// afterEach restores the container to its unfaked state.
test('authenticated user can access dashboard', function () {
    $user = User::factory()->create();

    WorkOS::actingAs($user);

    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('Dashboard');
})->afterEach(fn () => WorkOS::restore());

test('authenticated user can logout', function () {
    $user = User::factory()->create();

    WorkOS::actingAs($user);

    $this->get('/auth/logout')
        ->assertRedirect();
})->afterEach(fn () => WorkOS::restore());
