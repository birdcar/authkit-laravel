<?php

declare(strict_types=1);

use Authkit\Authkit\Events\GenericWorkosEvent;
use Authkit\Authkit\Events\Workos\UserCreated;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\User;
use WorkOS\WebhookVerification;

// No emulate and no MockHandler needed anywhere here: signature verification
// is local HMAC math, and valid signatures are built with the SDK's own
// WebhookVerification::computeSignature() rather than hand-rolling the HMAC
// logic a second time.

const WEBHOOK_SECRET = 'whsec_test_secret';

beforeEach(function (): void {
    $this->migratePackageDatabase();

    config()->set('authkit.webhooks.secret', WEBHOOK_SECRET);

    // The macro returns the registered Route, which the pipeline-inspection
    // tests read directly (name lookups are only refreshed when a routes file
    // loads, not for routes registered mid-test).
    $this->webhookRoute = Route::workosWebhooks('workos/webhooks');
});

function webhookBody(string $type = 'user.created', array $data = ['id' => 'user_01AAA', 'email' => 'alice@acme.com', 'email_verified' => true]): string
{
    return (string) json_encode([
        'id' => 'event_01WEBHOOK',
        'event' => $type,
        'data' => $data,
        'created_at' => '2026-08-06T12:00:00.000Z',
    ]);
}

function signatureHeader(string $body, ?int $timestampMs = null, string $secret = WEBHOOK_SECRET): string
{
    $timestamp = (string) ($timestampMs ?? (int) (microtime(true) * 1000));

    return sprintf('t=%s, v1=%s', $timestamp, WebhookVerification::computeSignature($timestamp, $body, $secret));
}

it('accepts a validly-signed webhook and dispatches the mapped typed event', function (): void {
    Event::fake([UserCreated::class]);

    $body = webhookBody();

    $response = $this->call('POST', '/workos/webhooks', [], [], [], [
        'HTTP_WORKOS-SIGNATURE' => signatureHeader($body),
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    $response->assertOk()->assertJson(['received' => true]);

    Event::assertDispatched(UserCreated::class, fn (UserCreated $event): bool => $event->id === 'event_01WEBHOOK'
        && $event->resourceId() === 'user_01AAA'
        && $event->occurredAt->format('Y-m-d\TH:i:s.v\Z') === '2026-08-06T12:00:00.000Z');
});

it('feeds the same projection listeners the poller feeds', function (): void {
    $body = webhookBody(data: ['id' => 'user_01AAA', 'email' => 'alice@acme.com', 'email_verified' => true, 'first_name' => 'Alice', 'last_name' => 'Anderson']);

    $this->call('POST', '/workos/webhooks', [], [], [], [
        'HTTP_WORKOS-SIGNATURE' => signatureHeader($body),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    expect(User::query()->where('workos_id', 'user_01AAA')->count())->toBe(1);
});

it('dispatches GenericWorkosEvent for out-of-scope types', function (): void {
    Event::fake([GenericWorkosEvent::class]);

    $body = webhookBody('dsync.user.created', ['id' => 'directory_user_01AAA']);

    $this->call('POST', '/workos/webhooks', [], [], [], [
        'HTTP_WORKOS-SIGNATURE' => signatureHeader($body),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    Event::assertDispatched(GenericWorkosEvent::class, fn (GenericWorkosEvent $event): bool => $event->type === 'dsync.user.created');
});

it('rejects a tampered body with 401 and dispatches nothing', function (): void {
    Event::fake();

    $body = webhookBody();
    $header = signatureHeader($body); // valid for the ORIGINAL body

    $tampered = str_replace('alice@acme.com', 'mallory@evil.com', $body);

    $this->call('POST', '/workos/webhooks', [], [], [], [
        'HTTP_WORKOS-SIGNATURE' => $header,
        'CONTENT_TYPE' => 'application/json',
    ], $tampered)->assertUnauthorized();

    Event::assertNotDispatched(UserCreated::class);
});

it('rejects a signature computed with the wrong secret', function (): void {
    Event::fake();

    $body = webhookBody();

    $this->call('POST', '/workos/webhooks', [], [], [], [
        'HTTP_WORKOS-SIGNATURE' => signatureHeader($body, secret: 'whsec_wrong'),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertUnauthorized();

    Event::assertNotDispatched(UserCreated::class);
});

it('rejects a malformed WorkOS-Signature header with 401', function (): void {
    Event::fake();

    $body = webhookBody();

    $this->call('POST', '/workos/webhooks', [], [], [], [
        'HTTP_WORKOS-SIGNATURE' => 'not-a-real-header',
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertUnauthorized();

    Event::assertNotDispatched(UserCreated::class);
});

it('accepts a timestamp 179s old but rejects one 181s old (exact tolerance boundary)', function (): void {
    Event::fake([UserCreated::class]);

    $body = webhookBody();

    $freshEnough = (int) ((microtime(true) - 179) * 1000);
    $this->call('POST', '/workos/webhooks', [], [], [], [
        'HTTP_WORKOS-SIGNATURE' => signatureHeader($body, $freshEnough),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    $tooOld = (int) ((microtime(true) - 181) * 1000);
    $this->call('POST', '/workos/webhooks', [], [], [], [
        'HTTP_WORKOS-SIGNATURE' => signatureHeader($body, $tooOld),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertUnauthorized();
});

it('fails fast with a RuntimeException naming the config key when the webhook secret is unset', function (): void {
    config()->set('authkit.webhooks.secret', null);

    $body = webhookBody();

    $this->withoutExceptionHandling()->call('POST', '/workos/webhooks', [], [], [], [
        'HTTP_WORKOS-SIGNATURE' => signatureHeader($body),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
})->throws(RuntimeException::class, '[authkit.webhooks.secret]');

it('excludes the CSRF middleware from the resolved route pipeline', function (): void {
    // PreventRequestForgery::handle() unconditionally bypasses itself whenever
    // runningUnitTests() is true, so the postJson-style cases above cannot
    // prove the macro's CSRF exclusion — a regression back to excluding only
    // the deprecated ValidateCsrfToken subclass would hide behind the test-mode
    // bypass. Assert directly on the route's excluded-middleware list instead.
    $excluded = $this->webhookRoute->excludedMiddleware();

    // The registrar's exclusion list is class_exists-filtered to whatever the
    // running framework ships: ValidateCsrfToken everywhere, plus its
    // PreventRequestForgery rename (the class the `web` group actually
    // applies) on Laravel 13 — which does not exist on the 12.x lanes.
    expect($excluded)->toContain(ValidateCsrfToken::class);

    if (class_exists(PreventRequestForgery::class)) {
        expect($excluded)->toContain(PreventRequestForgery::class);
    }
});

it('registers the macro route with the authkit.webhook middleware applied', function (): void {
    $route = $this->webhookRoute;

    expect($route->methods())->toContain('POST')
        ->and($route->uri())->toBe('workos/webhooks')
        ->and($route->getName())->toBe('authkit.webhooks')
        ->and($route->middleware())->toContain('authkit.webhook');
});
