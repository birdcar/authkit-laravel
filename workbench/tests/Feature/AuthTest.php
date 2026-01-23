<?php

declare(strict_types=1);

use App\Models\User;

test('guest is redirected to login page', function () {
    $this->get('/dashboard')
        ->assertRedirect('/auth/login');
});

test('login route redirects to workos', function () {
    config(['workos.client_id' => 'test_client_id']);

    $this->get('/auth/login')
        ->assertRedirect();
});

test('authenticated user can access dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'workos')
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Dashboard');
});

test('authenticated user can logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'workos')
        ->get('/auth/logout')
        ->assertRedirect();
});
