<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use WorkOS\AuthKit\Events\UserAuthenticated;
use WorkOS\AuthKit\Events\UserLoggedOut;

beforeEach(function () {
    Event::fake([UserAuthenticated::class, UserLoggedOut::class]);
});

it('redirects to workos login url', function () {
    $response = $this->get('/auth/login');

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)->toContain('api.workos.com')
        ->toContain('user_management/authorize')
        ->toContain('provider=authkit');
});

it('passes organization id to login url', function () {
    $response = $this->get('/auth/login?organization_id=org_123');

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)->toContain('organization_id=org_123');
});

it('passes return_to state to login url', function () {
    $response = $this->get('/auth/login?return_to=/dashboard');

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)->toContain('state=');
});

it('redirects to login with error when callback has no code', function () {
    $response = $this->get('/auth/callback');

    $response->assertRedirect(route('login'))
        ->assertSessionHas('error');
});

it('handles logout without active session', function () {
    $response = $this->get('/auth/logout');

    $response->assertRedirect('/');
    Event::assertNotDispatched(UserLoggedOut::class);
});
