<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use WorkOS\AuthKit\Events\UserAuthenticated;
use WorkOS\AuthKit\Events\UserLoggedOut;

beforeEach(function () {
    Event::fake([UserAuthenticated::class, UserLoggedOut::class]);
});

it('redirects to workos login url', function () {
    $this->queueSdkResponse(['url' => 'https://api.workos.com/user_management/authorize?provider=authkit&client_id=test']);

    $response = $this->get('/auth/login');

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)->toContain('api.workos.com')
        ->toContain('user_management/authorize')
        ->toContain('provider=authkit');
});

it('passes organization id to login url', function () {
    $this->queueSdkResponse(['url' => 'https://api.workos.com/user_management/authorize?provider=authkit&organization_id=org_123']);

    $response = $this->get('/auth/login?organization_id=org_123');

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)->toContain('organization_id=org_123');
});

it('passes return_to state to login url', function () {
    $this->queueSdkResponse(['url' => 'https://api.workos.com/user_management/authorize?provider=authkit&state=%7B%22return_to%22%3A%22%2Fdashboard%22%7D']);

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

it('passes screen hint to workos authorization url', function () {
    $this->queueSdkResponse(['url' => 'https://api.workos.com/user_management/authorize?provider=authkit&screen_hint=sign-up']);

    $response = $this->get('/auth/login?screen_hint=sign-up');

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)->toContain('screen_hint=sign-up');
});

it('passes login hint to workos authorization url', function () {
    $this->queueSdkResponse(['url' => 'https://api.workos.com/user_management/authorize?provider=authkit&login_hint=user%40example.com']);

    $response = $this->get('/auth/login?login_hint=user%40example.com');

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)->toContain('login_hint=user%40example.com');
});

it('passes both screen hint and login hint', function () {
    $this->queueSdkResponse(['url' => 'https://api.workos.com/user_management/authorize?provider=authkit&screen_hint=sign-up&login_hint=user%40example.com']);

    $response = $this->get('/auth/login?screen_hint=sign-up&login_hint=user%40example.com');

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)
        ->toContain('screen_hint=sign-up')
        ->toContain('login_hint=user%40example.com');
});
