<?php

declare(strict_types=1);

use Authkit\Authkit\Auth\WorkosApiKeyActor;
use Authkit\Authkit\Authorization\ApiKeyGateHook;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\Organization;
use Workbench\App\Models\User;

// Test path: pure unit — no WorkOS call. The actor is constructed directly and
// exercised through the real Gate (the package provider registers the API-key
// Gate::before hook), which is exactly how a guarded route would consume it.

function apiKeyActor(array $permissions, ?DateTimeImmutable $expiresAt = null): WorkosApiKeyActor
{
    return new WorkosApiKeyActor(
        organization: new Organization(['name' => 'Acme Corp']),
        permissions: $permissions,
        apiKeyId: 'api_key_01HTESTACTOR',
        expiresAt: $expiresAt,
    );
}

it('implements the Authenticatable contract around the key identity', function (): void {
    $actor = apiKeyActor(['posts:read']);

    expect($actor->getAuthIdentifierName())->toBe('api_key_id')
        ->and($actor->getAuthIdentifier())->toBe('api_key_01HTESTACTOR')
        ->and($actor->getAuthPasswordName())->toBe('password')
        ->and($actor->getAuthPassword())->toBe('')
        ->and($actor->getRememberToken())->toBeNull()
        ->and($actor->getRememberTokenName())->toBe('');
});

it('ignores setRememberToken — a stateless actor has no remember-me', function (): void {
    $actor = apiKeyActor([]);

    $actor->setRememberToken('anything');

    expect($actor->getRememberToken())->toBeNull();
});

it('exposes the wrapped local organization model', function (): void {
    $actor = apiKeyActor([]);

    expect($actor->organization)->toBeInstanceOf(Organization::class)
        ->and($actor->organization->getAttribute('name'))->toBe('Acme Corp');
});

it('grants an ability the key carries and default-denies one it does not', function (
    array $permissions,
    string $ability,
    bool $expected,
    ?DateTimeImmutable $expiresAt,
): void {
    $actor = apiKeyActor($permissions, $expiresAt);

    expect(Gate::forUser($actor)->allows($ability))->toBe($expected);
})->with([
    'carried permission' => [['posts:read', 'posts:write'], 'posts:read', true, null],
    'carried permission, expiring key' => [['posts:read'], 'posts:read', true, new DateTimeImmutable('+1 hour')],
    'uncarried ability (no policy exists for a synthetic actor)' => [['posts:read'], 'posts:delete', false, null],
    'empty permission set' => [[], 'posts:read', false, null],
]);

it('constructs with a null expiry — non-expiring keys are the WorkOS default', function (): void {
    $actor = apiKeyActor(['posts:read']);

    expect($actor->expiresAt)->toBeNull();
});

it('defers (null, never false) for an uncarried ability so policies still run', function (): void {
    $hook = new ApiKeyGateHook;

    $result = $hook(apiKeyActor(['posts:read']), 'posts:delete');

    expect($result)->toBeNull()
        ->and($result)->not->toBeFalse();
});

it('reads a user-scoped key permission set the guard attached via setApiKeyPermissions', function (): void {
    $hook = new ApiKeyGateHook;
    $user = (new User)->setApiKeyPermissions(['reports:run']);

    expect($hook($user, 'reports:run'))->toBeTrue()
        ->and($hook($user, 'reports:delete'))->toBeNull();
});

it('defers for a user the authkit-key guard did not resolve (no attached key permissions)', function (): void {
    $hook = new ApiKeyGateHook;

    expect($hook(new User, 'reports:run'))->toBeNull();
});
