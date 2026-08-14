<?php

declare(strict_types=1);

use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Invitations\InvitationManager;
use Authkit\Authkit\Models\WorkosMembership;
use Authkit\Authkit\Testing\Fakes\InvitationsFake;
use PHPUnit\Framework\AssertionFailedError;
use Workbench\App\Models\User;
use WorkOS\Resource\Invitation;
use WorkOS\Resource\UserInvite;
use WorkOS\Resource\UserInviteState;

function invitationsFake(): InvitationsFake
{
    $fake = new InvitationsFake;

    app()->instance(InvitationManager::class, $fake);

    return $fake;
}

it('mints a pending synthetic invitation on send', function (): void {
    $fake = invitationsFake();

    $invitation = Authkit::invitations()->send(
        'invitee@example.com',
        organizationId: 'org_acme',
        roleSlug: 'member',
        inviterUserId: 'user_admin',
    );

    expect($invitation)->toBeInstanceOf(UserInvite::class)
        ->and($invitation->state)->toBe(UserInviteState::Pending)
        ->and($invitation->email)->toBe('invitee@example.com')
        ->and($invitation->organizationId)->toBe('org_acme')
        ->and($invitation->roleSlug)->toBe('member')
        ->and($invitation->inviterUserId)->toBe('user_admin')
        ->and(Authkit::invitations()->acceptUrl($invitation))->toContain($invitation->token);

    $fake->assertSent('invitee@example.com');
    $fake->assertSent('invitee@example.com', fn (UserInvite $sent): bool => $sent->roleSlug === 'member');
});

it('reads sent invitations back through get, findByToken and list', function (): void {
    invitationsFake();

    $sent = Authkit::invitations()->send('invitee@example.com', organizationId: 'org_acme');
    Authkit::invitations()->send('other@example.com', organizationId: 'org_other');

    expect(Authkit::invitations()->get($sent->id)->id)->toBe($sent->id)
        ->and(Authkit::invitations()->findByToken($sent->token)->id)->toBe($sent->id)
        ->and(Authkit::invitations()->list(organizationId: 'org_acme')->data)->toHaveCount(1)
        ->and(Authkit::invitations()->list(email: 'other@example.com')->data)->toHaveCount(1)
        ->and(Authkit::invitations()->list()->data)->toHaveCount(2);
});

it('transitions state on revoke and accept', function (): void {
    $fake = invitationsFake();

    $first = Authkit::invitations()->send('revoke-me@example.com');
    $second = Authkit::invitations()->send('accept-me@example.com');

    $revoked = Authkit::invitations()->revoke($first->id);
    $accepted = Authkit::invitations()->accept($second->id);

    expect($revoked)->toBeInstanceOf(Invitation::class)
        ->and($revoked->state)->toBe(UserInviteState::Revoked)
        ->and($revoked->revokedAt)->not->toBeNull()
        ->and($accepted->state)->toBe(UserInviteState::Accepted)
        ->and($accepted->acceptedAt)->not->toBeNull()
        ->and(Authkit::invitations()->get($first->id)->state)->toBe(UserInviteState::Revoked)
        ->and(Authkit::invitations()->get($second->id)->state)->toBe(UserInviteState::Accepted);

    $fake->assertRevoked($first->id);
    $fake->assertAccepted($second->id);
});

it('records resends without duplicating the invitation', function (): void {
    $fake = invitationsFake();

    $sent = Authkit::invitations()->send('invitee@example.com');

    $resent = Authkit::invitations()->resend($sent->id);

    expect($resent->id)->toBe($sent->id)
        ->and(Authkit::invitations()->list()->data)->toHaveCount(1);

    $fake->assertResent($sent->id);
});

it('seeds fixture invitations without recording a send', function (): void {
    $fake = invitationsFake();

    $seeded = $fake->seed(['email' => 'existing@example.com', 'organization_id' => 'org_acme']);

    expect(Authkit::invitations()->get($seeded->id)->email)->toBe('existing@example.com');

    $fake->assertNothingSent();
});

it('throws with guidance for unknown invitations', function (): void {
    invitationsFake();

    expect(fn (): UserInvite => Authkit::invitations()->get('invitation_missing'))
        ->toThrow(InvalidArgumentException::class, 'send() or seed()')
        ->and(fn (): UserInvite => Authkit::invitations()->findByToken('nope'))
        ->toThrow(InvalidArgumentException::class, 'send() or seed()');
});

it('fails assertions with readable messages', function (): void {
    $fake = invitationsFake();

    expect(fn () => $fake->assertSent('nobody@example.com'))
        ->toThrow(AssertionFailedError::class, 'No invitations were sent');

    Authkit::invitations()->send('someone@example.com');

    expect(fn () => $fake->assertSent('nobody@example.com'))
        ->toThrow(AssertionFailedError::class, 'Sent to: someone@example.com')
        ->and(fn () => $fake->assertNothingSent())
        ->toThrow(AssertionFailedError::class, 'Sent to: someone@example.com');
});

it('accept mirrors the real projection side effect when a local user matches the email', function (): void {
    $this->migratePackageDatabase();

    $fake = invitationsFake();

    config()->set('authkit.user.model', User::class);

    User::query()->create([
        'name' => 'Invitee',
        'email' => 'invitee@example.com',
        'workos_id' => 'user_invitee',
    ]);

    $sent = Authkit::invitations()->send('invitee@example.com', organizationId: 'org_acme', roleSlug: 'admin');

    $accepted = Authkit::invitations()->accept($sent->id);

    expect($accepted->acceptedUserId)->toBe('user_invitee');

    expect(WorkosMembership::query()
        ->where('organization_id', 'org_acme')
        ->where('user_id', 'user_invitee')
        ->first())
        ->not->toBeNull()
        ->role->toBe('admin')
        ->status->toBe('active');
});

it('accept without a matching local user transitions state and projects nothing', function (): void {
    $this->migratePackageDatabase();

    invitationsFake();

    $sent = Authkit::invitations()->send('stranger@example.com', organizationId: 'org_acme');

    $accepted = Authkit::invitations()->accept($sent->id);

    expect($accepted->state)->toBe(UserInviteState::Accepted)
        ->and(WorkosMembership::query()->count())->toBe(0);
});
