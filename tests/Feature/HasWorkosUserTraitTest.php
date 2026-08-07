<?php

declare(strict_types=1);

use Authkit\Authkit\Exceptions\UnverifiedEmailCollisionException;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use GuzzleHttp\Psr7\Response;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;
use WorkOS\Resource\User as WorkosUserResource;

uses(UsesWorkosMockHandler::class);

beforeEach(function (): void {
    // The skeleton users table is not loaded automatically for package suites —
    // Orchestra\Testbench\TestCase does not include WithWorkbench — so it has to
    // run before this package's add_workos_id ALTER migration.
    $this->migratePackageDatabase();
});

function workosUserResource(array $overrides = []): WorkosUserResource
{
    return WorkosUserResource::fromArray(array_merge([
        'id' => 'user_remote',
        'email' => 'alice@acme.com',
        'email_verified' => true,
        'first_name' => 'Alice',
        'last_name' => 'Anderson',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ], $overrides));
}

function updateUserResponse(): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'id' => 'user_remote',
        'email' => 'alice@acme.com',
        'email_verified' => true,
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]));
}

it('creates a local user and links the external id on first login', function (): void {
    $this->fakeWorkosResponses([updateUserResponse()]);

    $user = User::findOrCreateForWorkosUser(workosUserResource());

    expect($user->exists)->toBeTrue()
        ->and($user->workos_id)->toBe('user_remote')
        ->and($user->email)->toBe('alice@acme.com')
        ->and($user->name)->toBe('Alice Anderson')
        ->and($this->workosRequestHistory)->toHaveCount(1);
});

it('links a pre-existing local account matched by email instead of duplicating it', function (): void {
    $this->fakeWorkosResponses([updateUserResponse()]);

    $existing = UserFactory::new()->create(['email' => 'alice@acme.com']);

    $user = User::findOrCreateForWorkosUser(workosUserResource());

    expect($user->getKey())->toBe($existing->getKey())
        ->and(User::query()->count())->toBe(1)
        ->and($user->workos_id)->toBe('user_remote');
});

it('refuses to link a pre-existing account when the WorkOS email is unverified', function (): void {
    // Account-takeover guard: if the environment allows sign-up without email
    // verification, matching on email alone would hand whoever registers
    // alice@acme.com the real Alice's local row and everything attached to it.
    $this->fakeWorkosResponses([]);

    $existing = UserFactory::new()->create(['email' => 'alice@acme.com']);

    expect(fn () => User::findOrCreateForWorkosUser(workosUserResource(['email_verified' => false])))
        ->toThrow(UnverifiedEmailCollisionException::class);

    expect($existing->fresh()?->workos_id)->toBeNull()
        ->and(User::query()->count())->toBe(1)
        ->and($this->workosRequestHistory)->toHaveCount(0);
});

it('creates a separate account for an unverified email that collides with nothing', function (): void {
    $this->fakeWorkosResponses([updateUserResponse()]);

    $user = User::findOrCreateForWorkosUser(workosUserResource(['email_verified' => false]));

    expect($user->exists)->toBeTrue()
        ->and($user->workos_id)->toBe('user_remote');
});

it('finds an already-linked user by workos_id', function (): void {
    $this->fakeWorkosResponses([updateUserResponse()]);

    $existing = UserFactory::new()->create(['email' => 'other@acme.com', 'workos_id' => 'user_remote']);

    $user = User::findOrCreateForWorkosUser(workosUserResource());

    expect($user->getKey())->toBe($existing->getKey())
        ->and(User::query()->count())->toBe(1);
});

it('skips the external id write when WorkOS already has the right value', function (): void {
    $this->fakeWorkosResponses([]);

    $existing = UserFactory::new()->create(['email' => 'alice@acme.com', 'workos_id' => 'user_remote']);

    User::findOrCreateForWorkosUser(workosUserResource(['external_id' => (string) $existing->getKey()]));

    // An API write here would consume rate-limit budget on every single login.
    expect($this->workosRequestHistory)->toHaveCount(0);
});

it('falls back to first and last name when the WorkOS user has no name', function (): void {
    $this->fakeWorkosResponses([updateUserResponse()]);

    $user = User::findOrCreateForWorkosUser(workosUserResource(['first_name' => 'Bob', 'last_name' => 'Brown']));

    expect($user->name)->toBe('Bob Brown');
});

it('holds claims and impersonator context in memory without persisting them', function (): void {
    $this->fakeWorkosResponses([updateUserResponse()]);

    $user = User::findOrCreateForWorkosUser(workosUserResource());

    expect($user->claims())->toBeNull()
        ->and($user->impersonator())->toBeNull();

    $user->setWorkosImpersonator(['email' => 'admin@acme.com', 'reason' => null]);

    expect($user->impersonator())->toBe(['email' => 'admin@acme.com', 'reason' => null])
        ->and($user->getAttributes())->not->toHaveKey('workosImpersonator');
});
