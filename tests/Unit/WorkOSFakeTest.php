<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User as Authenticatable;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;
use WorkOS\AuthKit\Testing\WorkOSFake;
use WorkOS\AuthKit\WorkOS;

class FakeTestUser extends Authenticatable
{
    use HasWorkOSId;
    use HasWorkOSPermissions;

    protected $fillable = ['workos_id', 'email', 'name'];

    public $workos_id = 'user_fake_123';

    public $email = 'fake@example.com';

    public $name = 'Fake User';
}

beforeEach(function () {
    config(['workos.guard' => 'web']);
});

afterEach(function () {
    WorkOS::restore();
});

it('WorkOS::fake() returns WorkOSFake instance', function () {
    $fake = WorkOS::fake();

    expect($fake)->toBeInstanceOf(WorkOSFake::class);
});

it('WorkOS::fake() replaces workos service in container', function () {
    $fake = WorkOS::fake();

    expect(app('workos'))->toBe($fake);
});

it('WorkOS::isFaked() returns true when faked', function () {
    expect(WorkOS::isFaked())->toBeFalse();

    WorkOS::fake();

    expect(WorkOS::isFaked())->toBeTrue();
});

it('WorkOS::restore() resets fake state', function () {
    WorkOS::fake();

    expect(WorkOS::isFaked())->toBeTrue();

    WorkOS::restore();

    expect(WorkOS::isFaked())->toBeFalse();
});

it('actingAs sets up user with roles and permissions', function () {
    $user = new FakeTestUser;
    $fake = WorkOS::fake();

    $fake->actingAs($user, ['admin'], ['users.read', 'users.write'], 'org_123');

    expect($fake->user())->toBe($user);
    expect($fake->hasRole('admin'))->toBeTrue();
    expect($fake->hasPermission('users.read'))->toBeTrue();
    expect($fake->hasPermission('users.write'))->toBeTrue();
    expect($fake->organizationId())->toBe('org_123');
});

it('actingAs attaches session to user with trait', function () {
    $user = new FakeTestUser;

    WorkOS::actingAs($user, ['admin'], ['users.read']);

    $session = $user->getWorkOSSession();

    expect($session)->not->toBeNull();
    expect($session->roles)->toContain('admin');
    expect($session->permissions)->toContain('users.read');
});

it('WorkOS::actingAs() is shorthand for fake()->actingAs()', function () {
    $user = new FakeTestUser;

    $fake = WorkOS::actingAs($user, ['manager'], ['reports.view']);

    expect($fake)->toBeInstanceOf(WorkOSFake::class);
    expect($fake->hasRole('manager'))->toBeTrue();
    expect($fake->hasPermission('reports.view'))->toBeTrue();
});

it('withRoles adds roles incrementally', function () {
    $user = new FakeTestUser;

    $fake = WorkOS::fake()
        ->actingAs($user, ['user'])
        ->withRoles(['admin', 'manager']);

    expect($fake->hasRole('user'))->toBeTrue();
    expect($fake->hasRole('admin'))->toBeTrue();
    expect($fake->hasRole('manager'))->toBeTrue();
});

it('withPermissions adds permissions incrementally', function () {
    $user = new FakeTestUser;

    $fake = WorkOS::fake()
        ->actingAs($user, [], ['read'])
        ->withPermissions(['write', 'delete']);

    expect($fake->hasPermission('read'))->toBeTrue();
    expect($fake->hasPermission('write'))->toBeTrue();
    expect($fake->hasPermission('delete'))->toBeTrue();
});

it('inOrganization sets organization id', function () {
    $user = new FakeTestUser;

    $fake = WorkOS::fake()
        ->actingAs($user)
        ->inOrganization('org_new_456');

    expect($fake->organizationId())->toBe('org_new_456');
});

it('impersonating sets impersonator data', function () {
    $user = new FakeTestUser;

    $fake = WorkOS::fake()
        ->actingAs($user)
        ->impersonating(['email' => 'admin@example.com', 'reason' => 'Support ticket']);

    expect($fake->isImpersonating())->toBeTrue();
});

it('session returns WorkOSSession when user is set', function () {
    $user = new FakeTestUser;

    $fake = WorkOS::fake()->actingAs($user, ['admin'], ['users.read'], 'org_123');

    $session = $fake->session();

    expect($session)->not->toBeNull();
    expect($session->roles)->toContain('admin');
    expect($session->permissions)->toContain('users.read');
    expect($session->organizationId)->toBe('org_123');
});

it('session returns null when no user', function () {
    $fake = WorkOS::fake();

    expect($fake->session())->toBeNull();
});

it('isAuthenticated returns true when user is set', function () {
    $user = new FakeTestUser;

    $fake = WorkOS::fake()->actingAs($user);

    expect($fake->isAuthenticated())->toBeTrue();
});

it('isAuthenticated returns false when no user', function () {
    $fake = WorkOS::fake();

    expect($fake->isAuthenticated())->toBeFalse();
});

it('assertAuthenticated passes when user is set', function () {
    $user = new FakeTestUser;

    $fake = WorkOS::fake()->actingAs($user);

    $fake->assertAuthenticated();
});

it('assertGuest passes when no user', function () {
    $fake = WorkOS::fake();

    $fake->assertGuest();
});

it('assertHasRole passes when user has role', function () {
    $user = new FakeTestUser;

    $fake = WorkOS::fake()->actingAs($user, ['admin']);

    $fake->assertHasRole('admin');
});

it('assertHasPermission passes when user has permission', function () {
    $user = new FakeTestUser;

    $fake = WorkOS::fake()->actingAs($user, [], ['users.read']);

    $fake->assertHasPermission('users.read');
});

it('assertInOrganization passes when in correct org', function () {
    $user = new FakeTestUser;

    $fake = WorkOS::fake()->actingAs($user, [], [], 'org_test');

    $fake->assertInOrganization('org_test');
});

it('validSession returns session when user is authenticated', function () {
    $user = new FakeTestUser;

    $fake = WorkOS::fake()->actingAs($user);

    expect($fake->validSession())->not->toBeNull();
    expect($fake->validSession()->userId)->toBe($fake->session()->userId);
});

it('refreshes session when adding roles', function () {
    $user = new FakeTestUser;

    $fake = WorkOS::fake()->actingAs($user, ['user']);

    expect($user->getWorkOSSession()->roles)->toBe(['user']);

    $fake->withRoles(['admin']);

    expect($user->getWorkOSSession()->roles)->toContain('user');
    expect($user->getWorkOSSession()->roles)->toContain('admin');
});

it('refreshes session when adding permissions', function () {
    $user = new FakeTestUser;

    $fake = WorkOS::fake()->actingAs($user, [], ['read']);

    expect($user->getWorkOSSession()->permissions)->toBe(['read']);

    $fake->withPermissions(['write']);

    expect($user->getWorkOSSession()->permissions)->toContain('read');
    expect($user->getWorkOSSession()->permissions)->toContain('write');
});

it('refreshes session when changing organization', function () {
    $user = new FakeTestUser;

    $fake = WorkOS::fake()->actingAs($user, [], [], 'org_1');

    expect($user->getWorkOSSession()->organizationId)->toBe('org_1');

    $fake->inOrganization('org_2');

    expect($user->getWorkOSSession()->organizationId)->toBe('org_2');
});

it('refreshes session when setting impersonator', function () {
    $user = new FakeTestUser;

    $fake = WorkOS::fake()->actingAs($user);

    expect($user->getWorkOSSession()->impersonator)->toBeNull();

    $fake->impersonating(['email' => 'admin@example.com']);

    expect($user->getWorkOSSession()->impersonator)->not->toBeNull();
});
