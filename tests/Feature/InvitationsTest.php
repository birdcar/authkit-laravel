<?php

declare(strict_types=1);

use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Tests\Support\EmulateServer;
use WorkOS\Exception\BadRequestException;
use WorkOS\Resource\UserInvite;
use WorkOS\Resource\UserInviteState;

uses()->group('depth-extensions');

// Test path: emulate — invitation flows are fully covered (send/list/get/
// by_token/accept/revoke/resend), so every case here rides the real wire.
// Emulate drift note: its resend route does not enforce the pending state
// real WorkOS requires, so the uncaught-ApiException case uses revoke twice.

afterEach(function (): void {
    if (isset($this->server)) {
        $this->server->stop();
    }
});

function startInvitationsEmulate(): EmulateServer
{
    $server = new EmulateServer(
        port: 4194,
        seedPath: __DIR__.'/../Fixtures/workos-emulate-depth.config.yaml',
    );
    $server->start();

    config()->set('authkit.emulate.enabled', true);
    config()->set('authkit.emulate.base_url', $server->baseUrl());
    app()->forgetInstance(WorkosClientManager::class);

    return $server;
}

describe('Invitations', function (): void {
    it('walks send → get → resend → revoke, asserting the state at every step, and surfaces the API error on a second revoke', function (): void {
        $this->server = startInvitationsEmulate();

        $sent = Authkit::invitations()->send(
            email: 'invitee@depth.example.com',
            organizationId: 'org_01DEPTH',
            roleSlug: 'member',
        );

        expect($sent->state)->toBe(UserInviteState::Pending)
            ->and($sent->organizationId)->toBe('org_01DEPTH');

        expect(Authkit::invitations()->get($sent->id)->state)->toBe(UserInviteState::Pending);

        $resent = Authkit::invitations()->resend($sent->id);

        expect($resent->state)->toBe(UserInviteState::Pending)
            ->and($resent->token)->not->toBe($sent->token);

        expect(Authkit::invitations()->revoke($sent->id)->state)->toBe(UserInviteState::Revoked)
            ->and(Authkit::invitations()->get($sent->id)->state)->toBe(UserInviteState::Revoked);

        // No swallowing: revoking an already-revoked invitation surfaces the
        // WorkOS exception uncaught.
        expect(fn () => Authkit::invitations()->revoke($sent->id))
            ->toThrow(BadRequestException::class);
    })->skip(fn (): bool => ! EmulateServer::isAvailable(), 'npx/node not available');

    it('walks send → accept → get, landing on the accepted state', function (): void {
        $this->server = startInvitationsEmulate();

        $sent = Authkit::invitations()->send(
            email: 'devon@depth.example.com',
            organizationId: 'org_01DEPTH',
        );

        expect($sent->state)->toBe(UserInviteState::Pending);

        $accepted = Authkit::invitations()->accept($sent->id);

        expect($accepted->state)->toBe(UserInviteState::Accepted)
            ->and(Authkit::invitations()->get($sent->id)->state)->toBe(UserInviteState::Accepted);
    })->skip(fn (): bool => ! EmulateServer::isAvailable(), 'npx/node not available');

    it('lists by organization and email, finds by token, and returns the accept URL verbatim', function (): void {
        $this->server = startInvitationsEmulate();

        $sent = Authkit::invitations()->send(
            email: 'lookup@depth.example.com',
            organizationId: 'org_01DEPTH',
        );

        $listed = Authkit::invitations()->list(
            organizationId: 'org_01DEPTH',
            email: 'lookup@depth.example.com',
        );

        expect($listed->data)->toHaveCount(1)
            ->and($listed->data[0])->toBeInstanceOf(UserInvite::class)
            ->and($listed->data[0]->id)->toBe($sent->id);

        $found = Authkit::invitations()->findByToken($sent->token);

        expect($found->id)->toBe($sent->id)
            ->and(Authkit::invitations()->acceptUrl($found))->toBe($sent->acceptInvitationUrl)
            ->and(Authkit::invitations()->acceptUrl($found))->toContain($sent->token);
    })->skip(fn (): bool => ! EmulateServer::isAvailable(), 'npx/node not available');
});
