<?php

declare(strict_types=1);

use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Models\WorkosMembership;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use GuzzleHttp\Psr7\Response;
use Workbench\App\Models\Organization;

uses(UsesWorkosMockHandler::class);

beforeEach(function (): void {
    $this->migratePackageDatabase();

    config()->set('authkit.organization.model', Organization::class);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function acceptedInvitationResponse(array $overrides = []): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(array_merge([
        'object' => 'invitation',
        'id' => 'invitation_accepted',
        'email' => 'invitee@example.com',
        'state' => 'accepted',
        'accepted_at' => '2026-01-02T00:00:00.000Z',
        'expires_at' => '2026-01-08T00:00:00.000Z',
        'organization_id' => 'org_invite',
        'accepted_user_id' => 'user_invitee',
        'role_slug' => 'member',
        'created_at' => '2026-01-01T00:00:00.000Z',
        'updated_at' => '2026-01-02T00:00:00.000Z',
        'token' => 'tok_accepted',
        'accept_invitation_url' => 'https://fake.workos.test/invite/tok_accepted',
    ], $overrides)));
}

function acceptedMembershipListResponse(): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'object' => 'list',
        'data' => [[
            'object' => 'organization_membership',
            'id' => 'om_projected',
            'user_id' => 'user_invitee',
            'organization_id' => 'org_invite',
            'status' => 'active',
            'directory_managed' => false,
            'created_at' => '2026-01-02T00:00:00.000Z',
            'updated_at' => '2026-01-02T00:00:00.000Z',
            'role' => ['slug' => 'member'],
            'roles' => [['slug' => 'member']],
            'user' => [
                'id' => 'user_invitee',
                'email' => 'invitee@example.com',
                'email_verified' => true,
                'created_at' => '2026-01-01T00:00:00.000Z',
                'updated_at' => '2026-01-01T00:00:00.000Z',
            ],
        ]],
        'list_metadata' => ['before' => null, 'after' => null],
    ]));
}

it('projects the membership synchronously after a successful accept', function (): void {
    Organization::query()->createQuietly(['name' => 'Inviting Org', 'workos_id' => 'org_invite']);

    $this->fakeWorkosResponses([
        acceptedInvitationResponse(),
        acceptedMembershipListResponse(),
    ]);

    $invitation = Authkit::invitations()->accept('invitation_accepted');

    expect($invitation->acceptedUserId)->toBe('user_invitee');

    expect(WorkosMembership::query()->where('workos_id', 'om_projected')->first())
        ->not->toBeNull()
        ->organization_id->toBe('org_invite')
        ->user_id->toBe('user_invitee')
        ->role->toBe('member')
        ->status->toBe('active');
});

it('creates the local org row too when the inviting org was never projected', function (): void {
    $this->fakeWorkosResponses([
        acceptedInvitationResponse(),
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'id' => 'org_invite',
            'name' => 'Inviting Org',
            'domains' => [],
            'metadata' => [],
            'external_id' => null,
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ])),
        acceptedMembershipListResponse(),
    ]);

    Authkit::invitations()->accept('invitation_accepted');

    expect(Organization::query()->where('workos_id', 'org_invite')->value('name'))->toBe('Inviting Org')
        ->and(WorkosMembership::query()->where('workos_id', 'om_projected')->exists())->toBeTrue();
});

it('never fails the accept when the projection backfill errors', function (): void {
    Organization::query()->createQuietly(['name' => 'Inviting Org', 'workos_id' => 'org_invite']);

    config()->set('authkit.max_retries', 0);

    $this->fakeWorkosResponses([
        acceptedInvitationResponse(),
        new Response(500, ['Content-Type' => 'application/json'], (string) json_encode(['message' => 'boom'])),
    ]);

    $invitation = Authkit::invitations()->accept('invitation_accepted');

    // Accepted remotely; the events pipeline converges the projection later.
    expect($invitation->state->value)->toBe('accepted')
        ->and(WorkosMembership::query()->count())->toBe(0);
});

it('skips projection entirely for invitations without an organization', function (): void {
    $this->fakeWorkosResponses([
        acceptedInvitationResponse(['organization_id' => null]),
    ]);

    Authkit::invitations()->accept('invitation_accepted');

    // One request only: no getOrganization, no membership list.
    expect($this->workosRequestHistory)->toHaveCount(1)
        ->and(WorkosMembership::query()->count())->toBe(0);
});
