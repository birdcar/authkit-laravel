<?php

declare(strict_types=1);

use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use WorkOS\Exception\NotFoundException;
use WorkOS\Exception\WorkOSException;
use WorkOS\PaginatedResponse;

uses(UsesWorkosMockHandler::class);

beforeEach(function (): void {
    $this->migratePackageDatabase();

    // Retries sleep between attempts — keep failure-path tests instant.
    config()->set('authkit.max_retries', 0);
    app()->forgetInstance(WorkosClientManager::class);
});

it('makes Http::preventStrayRequests() catch WorkOS SDK traffic', function (): void {
    Http::preventStrayRequests();

    expect(fn (): PaginatedResponse => Authkit::invitations()->list())
        ->toThrow(RuntimeException::class, 'without a matching fake');
});

it('serves WorkOS SDK calls from Http::fake and records them for assertSent', function (): void {
    Http::fake([
        'api.workos.com/*' => Http::response(['data' => [], 'list_metadata' => []]),
    ]);

    expect(Authkit::invitations()->list()->data)->toBe([]);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'user_management/invitations')
        && $request->hasHeader('Authorization'));
});

it('keeps the SDK exception mapping intact through the Laravel transport', function (): void {
    Http::fake([
        'api.workos.com/*' => Http::response(['message' => 'No such invitation.'], 404),
    ]);

    expect(fn (): mixed => Authkit::invitations()->get('invitation_missing'))
        ->toThrow(NotFoundException::class);
});

it('surfaces transport failures as the SDK connection exception', function (): void {
    Http::fake(function (): never {
        throw new ConnectionException('Connection refused');
    });

    // WorkOSException is an interface (Pest's toThrow would treat it as a
    // message substring) — assert the concrete SDK type the mapping produces.
    expect(fn (): PaginatedResponse => Authkit::invitations()->list())
        ->toThrow(WorkOS\Exception\ConnectionException::class, 'Connection failed');
});

it('restores the bare Guzzle transport via config', function (): void {
    config()->set('authkit.http.transport', 'guzzle');
    app()->forgetInstance(WorkosClientManager::class);

    Http::fake([
        'api.workos.com/*' => Http::response(['data' => [], 'list_metadata' => []]),
    ]);

    // Under the guzzle transport the Laravel factory never sees SDK traffic —
    // the call bypasses the fake (and here fails on the fixture credentials
    // long before any real network round-trip could be attempted).
    try {
        Authkit::invitations()->list();
    } catch (Throwable) {
        // Expected: the request went to the real transport, not the fake.
    }

    Http::assertNothingSent();
});

it('leaves the MockHandler harness authoritative when it binds a stack instance', function (): void {
    $this->fakeWorkosResponses([]);

    Http::preventStrayRequests(); // must NOT be what fails the call below

    expect(fn (): PaginatedResponse => Authkit::invitations()->list())
        ->toThrow(OutOfBoundsException::class, 'Mock queue is empty');
});
