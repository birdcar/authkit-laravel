<?php

declare(strict_types=1);

use App\Models\User;
use WorkOS\AuthKit\Testing\Concerns\InteractsWithWorkOS;
use WorkOS\AuthKit\WorkOS;

// -------------------------------------------------------------------------
// Pattern 1: Direct WorkOS::fake() with explicit afterEach restore
// -------------------------------------------------------------------------
// Use this pattern when you want full control over fake lifecycle, or when
// you are NOT using the InteractsWithWorkOS trait.
// -------------------------------------------------------------------------

it('can authenticate as a user and access a protected route', function () {
    $user = User::factory()->create();
    $fake = WorkOS::fake();

    $fake->actingAs($user);

    $this->get('/dashboard')->assertOk();
})->afterEach(fn () => WorkOS::restore());

it('can assert roles and organization context', function () {
    $user = User::factory()->create();
    $fake = WorkOS::fake();

    $fake->actingAs($user, roles: ['admin'], organizationId: 'org_abc123');

    $fake->assertHasRole('admin');
    $fake->assertInOrganization('org_abc123');
})->afterEach(fn () => WorkOS::restore());

it('can build up context incrementally with builder methods', function () {
    $user = User::factory()->create();
    $fake = WorkOS::fake()
        ->actingAs($user, roles: ['member'], permissions: ['todos.read'])
        ->withRoles(['admin'])
        ->withPermissions(['todos.write'])
        ->inOrganization('org_xyz');

    $fake->assertHasRole('member');
    $fake->assertHasRole('admin');
    $fake->assertHasPermission('todos.read');
    $fake->assertHasPermission('todos.write');
    $fake->assertInOrganization('org_xyz');
})->afterEach(fn () => WorkOS::restore());

// -------------------------------------------------------------------------
// Pattern 2: InteractsWithWorkOS trait (auto-teardown via Laravel lifecycle)
// -------------------------------------------------------------------------
// Use this pattern when you want zero boilerplate. The trait's actingAsWorkOS()
// method activates the fake and logs in the user in one call. Call fakeWorkOS()
// alone when you need the fake without authenticating.
// -------------------------------------------------------------------------

describe('using InteractsWithWorkOS trait', function () {
    uses(InteractsWithWorkOS::class);

    it('authenticates via actingAsWorkOS convenience method', function () {
        $user = User::factory()->create();
        $fake = $this->actingAsWorkOS($user, roles: ['editor'], permissions: ['todos.read']);

        $this->get('/dashboard')->assertOk();
        $fake->assertHasRole('editor');
        $fake->assertHasPermission('todos.read');
    });

    it('activates fake without authentication via fakeWorkOS', function () {
        $fake = $this->fakeWorkOS();

        $fake->assertGuest();
        $this->get('/dashboard')->assertRedirect('/auth/login');
    });
})->afterEach(fn () => WorkOS::restore());

// -------------------------------------------------------------------------
// Pattern 3: Audit assertions
// -------------------------------------------------------------------------
// assertAudited() verifies the given action was captured by the fake.
// assertNotAudited() verifies an action was NOT captured.
// -------------------------------------------------------------------------

it('captures and asserts audit events', function () {
    $user = User::factory()->create();
    $fake = WorkOS::fake()->actingAs($user);

    $fake->audit('todo.created', metadata: ['title' => 'My Task']);

    $fake->assertAudited('todo.created');
    $fake->assertNotAudited('todo.deleted');
})->afterEach(fn () => WorkOS::restore());
