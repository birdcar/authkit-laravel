<?php

declare(strict_types=1);

use Authkit\Authkit\Events\GenericWorkosEvent;
use Authkit\Authkit\Events\Workos\OrganizationCreated;
use Authkit\Authkit\Events\Workos\OrganizationDeleted;
use Authkit\Authkit\Events\Workos\OrganizationDomainCreated;
use Authkit\Authkit\Events\Workos\OrganizationDomainDeleted;
use Authkit\Authkit\Events\Workos\OrganizationDomainUpdated;
use Authkit\Authkit\Events\Workos\OrganizationDomainVerificationFailed;
use Authkit\Authkit\Events\Workos\OrganizationDomainVerified;
use Authkit\Authkit\Events\Workos\OrganizationMembershipCreated;
use Authkit\Authkit\Events\Workos\OrganizationMembershipDeleted;
use Authkit\Authkit\Events\Workos\OrganizationMembershipUpdated;
use Authkit\Authkit\Events\Workos\OrganizationUpdated;
use Authkit\Authkit\Events\Workos\UserCreated;
use Authkit\Authkit\Events\Workos\UserDeleted;
use Authkit\Authkit\Events\Workos\UserUpdated;
use Authkit\Authkit\Events\WorkosEventMapper;
use WorkOS\Resource\EventSchema;

function makeEventSchema(string $type, array $data = ['id' => 'res_01HZZZZZZZZZZZZZZZZZZZZZZZ'], string $id = 'event_01HZZZZZZZZZZZZZZZZZZZZZZZ'): EventSchema
{
    return new EventSchema(
        object: 'event',
        id: $id,
        event: $type,
        data: $data,
        createdAt: new DateTimeImmutable('2026-08-06T12:00:00.000Z'),
    );
}

dataset('bounded types', [
    'user.created' => ['user.created', UserCreated::class],
    'user.updated' => ['user.updated', UserUpdated::class],
    'user.deleted' => ['user.deleted', UserDeleted::class],
    'organization.created' => ['organization.created', OrganizationCreated::class],
    'organization.updated' => ['organization.updated', OrganizationUpdated::class],
    'organization.deleted' => ['organization.deleted', OrganizationDeleted::class],
    'organization_domain.created' => ['organization_domain.created', OrganizationDomainCreated::class],
    'organization_domain.updated' => ['organization_domain.updated', OrganizationDomainUpdated::class],
    'organization_domain.deleted' => ['organization_domain.deleted', OrganizationDomainDeleted::class],
    'organization_domain.verified' => ['organization_domain.verified', OrganizationDomainVerified::class],
    'organization_domain.verification_failed' => ['organization_domain.verification_failed', OrganizationDomainVerificationFailed::class],
    'organization_membership.created' => ['organization_membership.created', OrganizationMembershipCreated::class],
    'organization_membership.updated' => ['organization_membership.updated', OrganizationMembershipUpdated::class],
    'organization_membership.deleted' => ['organization_membership.deleted', OrganizationMembershipDeleted::class],
]);

it('maps each bounded type string to its exact typed event class', function (string $type, string $expectedClass): void {
    $schema = makeEventSchema($type, ['id' => 'res_123', 'name' => 'Acme']);

    $mapped = (new WorkosEventMapper)->map($schema);

    expect($mapped)->toBeInstanceOf($expectedClass)
        ->and($mapped::class)->toBe($expectedClass)
        ->and($mapped->id)->toBe('event_01HZZZZZZZZZZZZZZZZZZZZZZZ')
        ->and($mapped->payload)->toBe(['id' => 'res_123', 'name' => 'Acme'])
        ->and($mapped->occurredAt->format('Y-m-d\TH:i:s.v\Z'))->toBe('2026-08-06T12:00:00.000Z');
})->with('bounded types');

it('falls back to GenericWorkosEvent for an out-of-scope type', function (): void {
    $mapped = (new WorkosEventMapper)->map(makeEventSchema('dsync.user.created'));

    expect($mapped)->toBeInstanceOf(GenericWorkosEvent::class)
        ->and($mapped->type)->toBe('dsync.user.created')
        ->and($mapped->id)->toBe('event_01HZZZZZZZZZZZZZZZZZZZZZZZ')
        ->and($mapped->payload)->toBe(['id' => 'res_01HZZZZZZZZZZZZZZZZZZZZZZZ']);
});

it('falls back gracefully for a type WorkOS might add tomorrow, never throwing', function (): void {
    $mapped = (new WorkosEventMapper)->map(makeEventSchema('some_future.event'));

    expect($mapped)->toBeInstanceOf(GenericWorkosEvent::class)
        ->and($mapped->type)->toBe('some_future.event');
});

it('accepts an empty payload array without error', function (): void {
    $mapped = (new WorkosEventMapper)->map(makeEventSchema('not.a.real.type', []));

    expect($mapped)->toBeInstanceOf(GenericWorkosEvent::class)
        ->and($mapped->payload)->toBe([]);
});

it('exposes the resource id via resourceId(), distinct from the event object id', function (): void {
    $mapped = (new WorkosEventMapper)->map(makeEventSchema('user.created', ['id' => 'user_01ABC'], 'event_01DEF'));

    expect($mapped)->toBeInstanceOf(UserCreated::class)
        ->and($mapped->resourceId())->toBe('user_01ABC')
        ->and($mapped->id)->toBe('event_01DEF');
});

it('throws a descriptive exception when a typed payload carries no resource id', function (): void {
    $mapped = (new WorkosEventMapper)->map(makeEventSchema('user.created', ['email' => 'a@b.co']));

    expect($mapped)->toBeInstanceOf(UserCreated::class);

    /** @var UserCreated $mapped */
    $mapped->resourceId();
})->throws(RuntimeException::class, 'carries no string `id`');
