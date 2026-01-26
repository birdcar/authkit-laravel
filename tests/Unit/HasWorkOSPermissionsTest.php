<?php

declare(strict_types=1);

use WorkOS\AuthKit\Tests\Fixtures\TestUser;
use WorkOS\AuthKit\Tests\Helpers\WorkOSSessionFactory;

it('sets and gets workos session', function () {
    $user = new TestUser;
    $session = WorkOSSessionFactory::create();

    $user->setWorkOSSession($session);

    expect($user->getWorkOSSession())->toBe($session);
});

it('returns null when no session is set', function () {
    $user = new TestUser;

    expect($user->getWorkOSSession())->toBeNull();
});

it('checks for single role', function () {
    $user = new TestUser;
    $user->setWorkOSSession(WorkOSSessionFactory::withRoles(['admin', 'editor']));

    expect($user->hasWorkOSRole('admin'))->toBeTrue()
        ->and($user->hasWorkOSRole('viewer'))->toBeFalse();
});

it('returns false for role check when no session', function () {
    $user = new TestUser;

    expect($user->hasWorkOSRole('admin'))->toBeFalse();
});

it('checks for single permission', function () {
    $user = new TestUser;
    $user->setWorkOSSession(WorkOSSessionFactory::withPermissions(['read', 'write']));

    expect($user->hasWorkOSPermission('read'))->toBeTrue()
        ->and($user->hasWorkOSPermission('delete'))->toBeFalse();
});

it('returns false for permission check when no session', function () {
    $user = new TestUser;

    expect($user->hasWorkOSPermission('read'))->toBeFalse();
});

it('checks for any of multiple roles', function () {
    $user = new TestUser;
    $user->setWorkOSSession(WorkOSSessionFactory::withRoles(['editor']));

    expect($user->hasAnyWorkOSRole(['admin', 'editor']))->toBeTrue()
        ->and($user->hasAnyWorkOSRole(['admin', 'viewer']))->toBeFalse();
});

it('returns false for any role check when no session', function () {
    $user = new TestUser;

    expect($user->hasAnyWorkOSRole(['admin', 'editor']))->toBeFalse();
});

it('checks for all required permissions', function () {
    $user = new TestUser;
    $user->setWorkOSSession(WorkOSSessionFactory::withPermissions(['read', 'write', 'delete']));

    expect($user->hasAllWorkOSPermissions(['read', 'write']))->toBeTrue()
        ->and($user->hasAllWorkOSPermissions(['read', 'admin']))->toBeFalse();
});

it('returns true for empty permissions array', function () {
    $user = new TestUser;
    $user->setWorkOSSession(WorkOSSessionFactory::create());

    expect($user->hasAllWorkOSPermissions([]))->toBeTrue();
});

it('returns current organization id', function () {
    $user = new TestUser;
    $user->setWorkOSSession(WorkOSSessionFactory::create(organizationId: 'org_123'));

    expect($user->currentOrganizationId())->toBe('org_123');
});

it('returns null for organization id when no session', function () {
    $user = new TestUser;

    expect($user->currentOrganizationId())->toBeNull();
});

it('detects impersonation', function () {
    $user = new TestUser;
    $user->setWorkOSSession(WorkOSSessionFactory::impersonating());

    expect($user->isImpersonating())->toBeTrue();
});

it('returns false for impersonation when not impersonating', function () {
    $user = new TestUser;
    $user->setWorkOSSession(WorkOSSessionFactory::create());

    expect($user->isImpersonating())->toBeFalse();
});

it('returns false for impersonation when no session', function () {
    $user = new TestUser;

    expect($user->isImpersonating())->toBeFalse();
});

it('returns impersonator data', function () {
    $user = new TestUser;
    $impersonator = ['email' => 'admin@example.com'];
    $user->setWorkOSSession(WorkOSSessionFactory::impersonating($impersonator));

    expect($user->impersonator())->toBe($impersonator);
});

it('returns null for impersonator when not impersonating', function () {
    $user = new TestUser;
    $user->setWorkOSSession(WorkOSSessionFactory::create());

    expect($user->impersonator())->toBeNull();
});
