<?php

declare(strict_types=1);

use WorkOS\AuthKit\Events\Sync\WorkOSDsyncActivated;
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncDeleted;
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncGroupCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncGroupDeleted;
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncGroupUpdated;
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncGroupUserAdded;
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncGroupUserRemoved;
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncUserCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncUserDeleted;
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncUserUpdated;

it('WorkOSDsyncActivated exposes typed accessors', function () {
    $event = new WorkOSDsyncActivated([
        'id' => 'dir_01',
        'name' => 'Okta Directory',
        'organization_id' => 'org_01',
        'type' => 'okta scim v2.0',
        'state' => 'linked',
    ]);

    expect($event->directoryId())->toBe('dir_01')
        ->and($event->name())->toBe('Okta Directory')
        ->and($event->organizationId())->toBe('org_01')
        ->and($event->type())->toBe('okta scim v2.0')
        ->and($event->state())->toBe('linked');
});

it('WorkOSDsyncDeleted exposes typed accessors', function () {
    $event = new WorkOSDsyncDeleted([
        'id' => 'dir_01',
        'organization_id' => 'org_01',
        'type' => 'gsuite directory',
        'state' => 'deleted',
    ]);

    expect($event->directoryId())->toBe('dir_01')
        ->and($event->organizationId())->toBe('org_01');
});

it('WorkOSDsyncUserCreated exposes typed accessors', function () {
    $event = new WorkOSDsyncUserCreated([
        'id' => 'dir_user_01',
        'directory_id' => 'dir_01',
        'organization_id' => 'org_01',
        'idp_id' => 'idp_user_01',
        'email' => 'alice@example.com',
        'first_name' => 'Alice',
        'last_name' => 'Smith',
        'state' => 'active',
        'custom_attributes' => ['department' => 'Engineering'],
    ]);

    expect($event->directoryUserId())->toBe('dir_user_01')
        ->and($event->directoryId())->toBe('dir_01')
        ->and($event->email())->toBe('alice@example.com')
        ->and($event->firstName())->toBe('Alice')
        ->and($event->lastName())->toBe('Smith')
        ->and($event->state())->toBe('active')
        ->and($event->customAttributes())->toBe(['department' => 'Engineering']);
});

it('WorkOSDsyncUserCreated returns null for missing optional fields', function () {
    $event = new WorkOSDsyncUserCreated([
        'id' => 'dir_user_01',
        'directory_id' => 'dir_01',
        'organization_id' => 'org_01',
        'idp_id' => 'idp_user_01',
        'email' => 'alice@example.com',
        'state' => 'active',
    ]);

    expect($event->firstName())->toBeNull()
        ->and($event->lastName())->toBeNull()
        ->and($event->customAttributes())->toBe([]);
});

it('WorkOSDsyncUserUpdated includes previousAttributes', function () {
    $event = new WorkOSDsyncUserUpdated([
        'id' => 'dir_user_01',
        'directory_id' => 'dir_01',
        'organization_id' => 'org_01',
        'idp_id' => 'idp_user_01',
        'email' => 'alice@example.com',
        'state' => 'active',
        'previous_attributes' => ['email' => 'old@example.com'],
    ]);

    expect($event->previousAttributes())->toBe(['email' => 'old@example.com']);
});

it('WorkOSDsyncUserDeleted exposes minimal accessors', function () {
    $event = new WorkOSDsyncUserDeleted([
        'id' => 'dir_user_01',
        'directory_id' => 'dir_01',
        'organization_id' => 'org_01',
        'email' => 'alice@example.com',
        'state' => 'inactive',
    ]);

    expect($event->directoryUserId())->toBe('dir_user_01')
        ->and($event->email())->toBe('alice@example.com');
});

it('WorkOSDsyncGroupCreated exposes typed accessors', function () {
    $event = new WorkOSDsyncGroupCreated([
        'id' => 'dir_group_01',
        'directory_id' => 'dir_01',
        'organization_id' => 'org_01',
        'idp_id' => 'idp_group_01',
        'name' => 'Engineering',
    ]);

    expect($event->directoryGroupId())->toBe('dir_group_01')
        ->and($event->name())->toBe('Engineering');
});

it('WorkOSDsyncGroupUpdated includes previousAttributes', function () {
    $event = new WorkOSDsyncGroupUpdated([
        'id' => 'dir_group_01',
        'directory_id' => 'dir_01',
        'organization_id' => 'org_01',
        'idp_id' => 'idp_group_01',
        'name' => 'Platform',
        'previous_attributes' => ['name' => 'Engineering'],
    ]);

    expect($event->previousAttributes())->toBe(['name' => 'Engineering']);
});

it('WorkOSDsyncGroupDeleted exposes minimal accessors', function () {
    $event = new WorkOSDsyncGroupDeleted([
        'id' => 'dir_group_01',
        'directory_id' => 'dir_01',
        'organization_id' => 'org_01',
        'name' => 'Engineering',
    ]);

    expect($event->directoryGroupId())->toBe('dir_group_01')
        ->and($event->name())->toBe('Engineering');
});

it('WorkOSDsyncGroupUserAdded exposes nested user and group', function () {
    $event = new WorkOSDsyncGroupUserAdded([
        'directory_id' => 'dir_01',
        'user' => ['id' => 'dir_user_01', 'email' => 'alice@example.com'],
        'group' => ['id' => 'dir_group_01', 'name' => 'Engineering'],
    ]);

    expect($event->directoryId())->toBe('dir_01')
        ->and($event->directoryUserId())->toBe('dir_user_01')
        ->and($event->directoryGroupId())->toBe('dir_group_01')
        ->and($event->userEmail())->toBe('alice@example.com')
        ->and($event->groupName())->toBe('Engineering')
        ->and($event->user())->toBe(['id' => 'dir_user_01', 'email' => 'alice@example.com'])
        ->and($event->group())->toBe(['id' => 'dir_group_01', 'name' => 'Engineering']);
});

it('WorkOSDsyncGroupUserRemoved exposes same shape as added', function () {
    $event = new WorkOSDsyncGroupUserRemoved([
        'directory_id' => 'dir_01',
        'user' => ['id' => 'dir_user_02', 'email' => 'bob@example.com'],
        'group' => ['id' => 'dir_group_01', 'name' => 'Engineering'],
    ]);

    expect($event->directoryUserId())->toBe('dir_user_02')
        ->and($event->userEmail())->toBe('bob@example.com');
});
