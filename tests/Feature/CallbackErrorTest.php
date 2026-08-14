<?php

declare(strict_types=1);

use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;

uses(UsesWorkosMockHandler::class);

beforeEach(function (): void {
    // No wire responses queued anywhere in this file on purpose: every one of
    // these callbacks must fail BEFORE any code exchange is attempted.
    $this->fakeWorkosResponses([]);
});

it('lands an OAuth error callback on home with a friendly flash instead of looping', function (): void {
    // The pre-fix behavior: `?error=...` carries no code, the code/state rules
    // fail, and the validation redirect sends the browser BACK — to the
    // authorize URL — which re-runs the redirect and loops forever (observed
    // live against workos/emulate's `no_users`).
    $response = $this->get('/authkit/callback?error=no_users&state=abc123');

    $response->assertRedirect('/');
    $response->assertSessionHasErrors('authkit');
});

it('tells a user who cancelled sign-in that they can retry, without restarting the flow', function (): void {
    $response = $this->get('/authkit/callback?error=access_denied');

    $response->assertRedirect('/');
    $response->assertSessionHasErrors(['authkit' => 'Sign-in was cancelled. You can try again whenever you are ready.']);
});

it('gives a bookmarked or replayed callback the same friendly landing as an OAuth error', function (): void {
    $response = $this->get('/authkit/callback');

    $response->assertRedirect('/');
    $response->assertSessionHasErrors('authkit');
});
