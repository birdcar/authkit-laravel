<?php

declare(strict_types=1);

use Carbon\Carbon;
use WorkOS\AuthKit\Auth\WorkOSSession;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;

class TestUserWithPermissions
{
    use HasWorkOSPermissions;
}

function createTestSession(array $roles = [], array $permissions = [], ?array $impersonator = null, ?string $organizationId = null): WorkOSSession
{
    return new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: null,
        expiresAt: Carbon::now()->addHour(),
        sessionId: 'session_456',
        roles: $roles,
        permissions: $permissions,
        organizationId: $organizationId,
        impersonator: $impersonator,
    );
}

it('sets and gets workos session', function () {
    $user = new TestUserWithPermissions;
    $session = createTestSession();

    $user->setWorkOSSession($session);

    expect($user->getWorkOSSession())->toBe($session);
});

it('returns null when no session is set', function () {
    $user = new TestUserWithPermissions;

    expect($user->getWorkOSSession())->toBeNull();
});

it('checks for single role', function () {
    $user = new TestUserWithPermissions;
    $user->setWorkOSSession(createTestSession(roles: ['admin', 'editor']));

    expect($user->hasWorkOSRole('admin'))->toBeTrue()
        ->and($user->hasWorkOSRole('viewer'))->toBeFalse();
});

it('returns false for role check when no session', function () {
    $user = new TestUserWithPermissions;

    expect($user->hasWorkOSRole('admin'))->toBeFalse();
});

it('checks for single permission', function () {
    $user = new TestUserWithPermissions;
    $user->setWorkOSSession(createTestSession(permissions: ['read', 'write']));

    expect($user->hasWorkOSPermission('read'))->toBeTrue()
        ->and($user->hasWorkOSPermission('delete'))->toBeFalse();
});

it('returns false for permission check when no session', function () {
    $user = new TestUserWithPermissions;

    expect($user->hasWorkOSPermission('read'))->toBeFalse();
});

it('checks for any of multiple roles', function () {
    $user = new TestUserWithPermissions;
    $user->setWorkOSSession(createTestSession(roles: ['editor']));

    expect($user->hasAnyWorkOSRole(['admin', 'editor']))->toBeTrue()
        ->and($user->hasAnyWorkOSRole(['admin', 'viewer']))->toBeFalse();
});

it('returns false for any role check when no session', function () {
    $user = new TestUserWithPermissions;

    expect($user->hasAnyWorkOSRole(['admin', 'editor']))->toBeFalse();
});

it('checks for all required permissions', function () {
    $user = new TestUserWithPermissions;
    $user->setWorkOSSession(createTestSession(permissions: ['read', 'write', 'delete']));

    expect($user->hasAllWorkOSPermissions(['read', 'write']))->toBeTrue()
        ->and($user->hasAllWorkOSPermissions(['read', 'admin']))->toBeFalse();
});

it('returns true for empty permissions array', function () {
    $user = new TestUserWithPermissions;
    $user->setWorkOSSession(createTestSession());

    expect($user->hasAllWorkOSPermissions([]))->toBeTrue();
});

it('returns current organization id', function () {
    $user = new TestUserWithPermissions;
    $user->setWorkOSSession(createTestSession(organizationId: 'org_123'));

    expect($user->currentOrganizationId())->toBe('org_123');
});

it('returns null for organization id when no session', function () {
    $user = new TestUserWithPermissions;

    expect($user->currentOrganizationId())->toBeNull();
});

it('detects impersonation', function () {
    $user = new TestUserWithPermissions;
    $impersonator = ['email' => 'admin@example.com'];
    $user->setWorkOSSession(createTestSession(impersonator: $impersonator));

    expect($user->isImpersonating())->toBeTrue();
});

it('returns false for impersonation when not impersonating', function () {
    $user = new TestUserWithPermissions;
    $user->setWorkOSSession(createTestSession());

    expect($user->isImpersonating())->toBeFalse();
});

it('returns false for impersonation when no session', function () {
    $user = new TestUserWithPermissions;

    expect($user->isImpersonating())->toBeFalse();
});

it('returns impersonator data', function () {
    $user = new TestUserWithPermissions;
    $impersonator = ['email' => 'admin@example.com'];
    $user->setWorkOSSession(createTestSession(impersonator: $impersonator));

    expect($user->impersonator())->toBe($impersonator);
});

it('returns null for impersonator when not impersonating', function () {
    $user = new TestUserWithPermissions;
    $user->setWorkOSSession(createTestSession());

    expect($user->impersonator())->toBeNull();
});
