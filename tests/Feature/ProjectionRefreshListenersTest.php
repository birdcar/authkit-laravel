<?php

declare(strict_types=1);

use Authkit\Authkit\Events\Workos\OrganizationCreated;
use Authkit\Authkit\Events\Workos\OrganizationDeleted;
use Authkit\Authkit\Events\Workos\OrganizationDomainCreated;
use Authkit\Authkit\Events\Workos\OrganizationDomainDeleted;
use Authkit\Authkit\Events\Workos\OrganizationDomainVerificationFailed;
use Authkit\Authkit\Events\Workos\OrganizationDomainVerified;
use Authkit\Authkit\Events\Workos\OrganizationMembershipCreated;
use Authkit\Authkit\Events\Workos\OrganizationMembershipDeleted;
use Authkit\Authkit\Events\Workos\OrganizationMembershipUpdated;
use Authkit\Authkit\Events\Workos\OrganizationUpdated;
use Authkit\Authkit\Events\Workos\UserCreated;
use Authkit\Authkit\Events\Workos\UserDeleted;
use Authkit\Authkit\Events\Workos\UserUpdated;
use Authkit\Authkit\Models\WorkosMembership;
use Authkit\Authkit\Models\WorkosOrganizationDomain;
use Illuminate\Support\Facades\Log;
use Workbench\App\Models\Organization;
use Workbench\App\Models\User;

// Listeners are dispatched through the real event bus (event()) against the
// Testbench SQLite database — no poller, no HTTP involved. Every case doubles
// as an idempotency check: WorkOS delivery is at-least-once, so re-delivery
// with a DIFFERENT event id but the SAME resource id must never duplicate.

beforeEach(function (): void {
    $this->migratePackageDatabase();

    // Keep the org-create observer's sync job in-request and API-free: the
    // projection rows this suite creates always carry workos_id, so the job's
    // "already synced?" first line no-ops before any HTTP call.
    config()->set('authkit.organization.sync_mode', 'sync');
});

function typed(string $class, array $payload, string $eventId = 'event_01AAA'): object
{
    return new $class(
        id: $eventId,
        payload: $payload,
        occurredAt: new DateTimeImmutable('2026-08-06T12:00:00.000Z'),
    );
}

it('creates exactly one user row when the same UserCreated payload is dispatched twice', function (): void {
    $payload = ['id' => 'user_01AAA', 'email' => 'alice@acme.com', 'email_verified' => true, 'first_name' => 'Alice', 'last_name' => 'Anderson'];

    event(typed(UserCreated::class, $payload, 'event_01AAA'));
    event(typed(UserCreated::class, $payload, 'event_01BBB'));

    expect(User::query()->where('workos_id', 'user_01AAA')->count())->toBe(1)
        ->and(User::query()->firstWhere('workos_id', 'user_01AAA')?->email)->toBe('alice@acme.com')
        ->and(User::query()->firstWhere('workos_id', 'user_01AAA')?->name)->toBe('Alice Anderson');
});

it('updates the existing user row on UserUpdated', function (): void {
    event(typed(UserCreated::class, ['id' => 'user_01AAA', 'email' => 'alice@acme.com', 'email_verified' => true, 'first_name' => 'Alice', 'last_name' => 'Anderson']));
    event(typed(UserUpdated::class, ['id' => 'user_01AAA', 'email' => 'alice@example.com', 'email_verified' => true, 'name' => 'Alice A.']));

    expect(User::query()->count())->toBe(1)
        ->and(User::query()->firstWhere('workos_id', 'user_01AAA')?->email)->toBe('alice@example.com')
        ->and(User::query()->firstWhere('workos_id', 'user_01AAA')?->name)->toBe('Alice A.');
});

it('links a verified WorkOS user to an existing local account by email instead of duplicating it', function (): void {
    $local = User::query()->forceCreate(['name' => 'Pre WorkOS', 'email' => 'alice@acme.com', 'password' => 'irrelevant']);

    event(typed(UserCreated::class, ['id' => 'user_01AAA', 'email' => 'alice@acme.com', 'email_verified' => true]));

    expect(User::query()->count())->toBe(1)
        ->and(User::query()->find($local->id)?->workos_id)->toBe('user_01AAA');
});

it('skips (never throws, never links) when an UNVERIFIED email collides with an existing local account', function (): void {
    Log::spy();
    User::query()->forceCreate(['name' => 'Pre WorkOS', 'email' => 'alice@acme.com', 'password' => 'irrelevant']);

    event(typed(UserCreated::class, ['id' => 'user_01AAA', 'email' => 'alice@acme.com', 'email_verified' => false]));

    expect(User::query()->count())->toBe(1)
        ->and(User::query()->firstWhere('email', 'alice@acme.com')?->workos_id)->toBeNull();

    Log::shouldHaveReceived('warning')->once();
});

it('skips the email write (never throws) when a user.updated email collides with a different local account', function (): void {
    event(typed(UserCreated::class, ['id' => 'user_01AAA', 'email' => 'alice@acme.com', 'email_verified' => true, 'name' => 'Alice']));
    User::query()->forceCreate(['name' => 'Bob', 'email' => 'bob@acme.com', 'password' => 'irrelevant']);

    Log::spy();

    // Alice's WorkOS email is changed to Bob's local address — writing it
    // would hit the unique email column on every at-least-once replay.
    event(typed(UserUpdated::class, ['id' => 'user_01AAA', 'email' => 'bob@acme.com', 'email_verified' => true, 'name' => 'Alice A.'], 'event_01ZZZ'));

    $alice = User::query()->firstWhere('workos_id', 'user_01AAA');
    expect($alice?->email)->toBe('alice@acme.com')
        ->and($alice?->name)->toBe('Alice A.')
        ->and(User::query()->count())->toBe(2);

    Log::shouldHaveReceived('warning')->once();
});

it('deletes the user row created by an earlier event, keyed on the RESOURCE id across differing event ids', function (): void {
    // Two distinct real deliveries: different $event->id values, same resource.
    // This is the regression guard for keying deletes off resourceId() rather
    // than the Event object's own id.
    event(typed(UserCreated::class, ['id' => 'user_01AAA', 'email' => 'alice@acme.com', 'email_verified' => true], 'event_01AAAaaa'));
    event(typed(UserDeleted::class, ['id' => 'user_01AAA'], 'event_01BBBbbb'));

    expect(User::query()->where('workos_id', 'user_01AAA')->exists())->toBeFalse();
});

it('is a no-op when UserDeleted arrives for a user never created locally', function (): void {
    event(typed(UserDeleted::class, ['id' => 'user_01NEVER']));

    expect(User::query()->count())->toBe(0);
});

it('creates exactly one organization row across duplicate OrganizationCreated deliveries and updates it in place', function (): void {
    event(typed(OrganizationCreated::class, ['id' => 'org_01AAA', 'name' => 'Acme'], 'event_01AAA'));
    event(typed(OrganizationCreated::class, ['id' => 'org_01AAA', 'name' => 'Acme'], 'event_01BBB'));
    event(typed(OrganizationUpdated::class, ['id' => 'org_01AAA', 'name' => 'Acme Corp'], 'event_01CCC'));

    expect(Organization::query()->where('workos_id', 'org_01AAA')->count())->toBe(1)
        ->and(Organization::query()->firstWhere('workos_id', 'org_01AAA')?->name)->toBe('Acme Corp');
});

it('deletes the organization projection on OrganizationDeleted and no-ops when it never existed', function (): void {
    event(typed(OrganizationCreated::class, ['id' => 'org_01AAA', 'name' => 'Acme']));
    event(typed(OrganizationDeleted::class, ['id' => 'org_01AAA'], 'event_01DDD'));
    event(typed(OrganizationDeleted::class, ['id' => 'org_01NEVER'], 'event_01EEE'));

    expect(Organization::query()->count())->toBe(0);
});

it('no-ops on organization events when the app has not configured an organization model', function (): void {
    config()->set('authkit.organization.model', null);

    event(typed(OrganizationCreated::class, ['id' => 'org_01AAA', 'name' => 'Acme']));
    event(typed(OrganizationDeleted::class, ['id' => 'org_01AAA']));

    expect(Organization::query()->count())->toBe(0);
});

it('upserts the domain projection idempotently and flips verification state without touching unrelated columns', function (): void {
    event(typed(OrganizationDomainCreated::class, [
        'id' => 'org_domain_01AAA',
        'organization_id' => 'org_01AAA',
        'domain' => 'acme.com',
        'state' => 'pending',
        'verification_token' => 'token-123',
    ], 'event_01AAA'));
    event(typed(OrganizationDomainCreated::class, [
        'id' => 'org_domain_01AAA',
        'organization_id' => 'org_01AAA',
        'domain' => 'acme.com',
        'state' => 'pending',
        'verification_token' => 'token-123',
    ], 'event_01BBB'));

    expect(WorkosOrganizationDomain::query()->count())->toBe(1);

    event(typed(OrganizationDomainVerified::class, [
        'id' => 'org_domain_01AAA',
        'organization_id' => 'org_01AAA',
        'domain' => 'acme.com',
        'state' => 'verified',
    ], 'event_01CCC'));

    $domain = WorkosOrganizationDomain::query()->firstWhere('workos_id', 'org_domain_01AAA');
    expect($domain?->state)->toBe('verified')
        ->and($domain?->verification_token)->toBe('token-123')
        ->and(WorkosOrganizationDomain::query()->count())->toBe(1);
});

it('keeps the last-known state when a verification_failed payload carries no top-level state', function (): void {
    event(typed(OrganizationDomainCreated::class, [
        'id' => 'org_domain_01AAA',
        'organization_id' => 'org_01AAA',
        'domain' => 'acme.com',
        'state' => 'pending',
    ]));

    // Real verification_failed payloads carry `reason` + nested state, never a
    // top-level `state` string — absent keys must not null out columns.
    event(typed(OrganizationDomainVerificationFailed::class, [
        'id' => 'org_domain_01AAA',
        'reason' => 'DNS record not found',
    ], 'event_01FFF'));

    $domain = WorkosOrganizationDomain::query()->firstWhere('workos_id', 'org_domain_01AAA');
    expect($domain?->state)->toBe('pending')
        ->and($domain?->domain)->toBe('acme.com');
});

it('deletes the domain projection and no-ops for a domain never projected', function (): void {
    event(typed(OrganizationDomainCreated::class, ['id' => 'org_domain_01AAA', 'organization_id' => 'org_01AAA', 'domain' => 'acme.com']));
    event(typed(OrganizationDomainDeleted::class, ['id' => 'org_domain_01AAA'], 'event_01GGG'));
    event(typed(OrganizationDomainDeleted::class, ['id' => 'org_domain_01NEVER'], 'event_01HHH'));

    expect(WorkosOrganizationDomain::query()->count())->toBe(0);
});

it('upserts the membership projection idempotently, mapping the nested role slug', function (): void {
    $payload = [
        'id' => 'om_01AAA',
        'organization_id' => 'org_01AAA',
        'user_id' => 'user_01AAA',
        'status' => 'active',
        'role' => ['slug' => 'member'],
    ];

    event(typed(OrganizationMembershipCreated::class, $payload, 'event_01AAA'));
    event(typed(OrganizationMembershipCreated::class, $payload, 'event_01BBB'));

    expect(WorkosMembership::query()->count())->toBe(1);

    $membership = WorkosMembership::query()->firstWhere('workos_id', 'om_01AAA');
    expect($membership?->organization_id)->toBe('org_01AAA')
        ->and($membership?->user_id)->toBe('user_01AAA')
        ->and($membership?->role)->toBe('member')
        ->and($membership?->status)->toBe('active');
});

it('updates membership role and status in place on OrganizationMembershipUpdated', function (): void {
    event(typed(OrganizationMembershipCreated::class, [
        'id' => 'om_01AAA', 'organization_id' => 'org_01AAA', 'user_id' => 'user_01AAA',
        'status' => 'active', 'role' => ['slug' => 'member'],
    ]));
    event(typed(OrganizationMembershipUpdated::class, [
        'id' => 'om_01AAA', 'organization_id' => 'org_01AAA', 'user_id' => 'user_01AAA',
        'status' => 'inactive', 'role' => ['slug' => 'admin'],
    ], 'event_01III'));

    $membership = WorkosMembership::query()->firstWhere('workos_id', 'om_01AAA');
    expect(WorkosMembership::query()->count())->toBe(1)
        ->and($membership?->role)->toBe('admin')
        ->and($membership?->status)->toBe('inactive');
});

it('is a no-op when OrganizationMembershipDeleted arrives for a membership never created locally', function (): void {
    event(typed(OrganizationMembershipDeleted::class, ['id' => 'om_01NEVER']));

    expect(WorkosMembership::query()->count())->toBe(0);
});

it('deletes the membership row created by an earlier event across differing event ids', function (): void {
    event(typed(OrganizationMembershipCreated::class, [
        'id' => 'om_01AAA', 'organization_id' => 'org_01AAA', 'user_id' => 'user_01AAA',
        'status' => 'active', 'role' => ['slug' => 'member'],
    ], 'event_01AAAaaa'));
    event(typed(OrganizationMembershipDeleted::class, ['id' => 'om_01AAA'], 'event_01BBBbbb'));

    expect(WorkosMembership::query()->count())->toBe(0);
});
