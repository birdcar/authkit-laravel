<?php

declare(strict_types=1);

namespace Authkit\Authkit\Invitations;

use Authkit\Authkit\Contracts\WorkosClientManager;
use WorkOS\PaginatedResponse;
use WorkOS\RequestOptions;
use WorkOS\Resource\CreateUserInviteOptionsLocale;
use WorkOS\Resource\Invitation;
use WorkOS\Resource\UserInvite;

/**
 * Invitations management surface, resolved via Authkit::invitations(). Pure
 * WorkOS passthroughs — invitations are canonical WorkOS state, never
 * projected locally. AuthKit's hosted UI accepts invitations itself during
 * the standard login/callback flow (the token rides the authorization URL);
 * accept() exists for apps building a custom acceptance UI ahead of the
 * redirect.
 */
class InvitationManager
{
    public function __construct(private readonly WorkosClientManager $clients) {}

    /**
     * List invitations, optionally filtered by organization and/or recipient
     * email. Items are UserInvite instances.
     */
    public function list(
        ?string $organizationId = null,
        ?string $email = null,
        ?string $before = null,
        ?string $after = null,
        ?int $limit = null,
    ): PaginatedResponse {
        return $this->clients->client()->userManagement()->listInvitations(
            before: $before,
            after: $after,
            limit: $limit,
            organizationId: $organizationId,
            email: $email,
        );
    }

    /**
     * Send an invitation email. Callers on a retryable code path (queued
     * jobs) should pass $idempotencyKey so a retry cannot double-send the
     * email (spec-phase-12 Failure Mode 6).
     */
    public function send(
        string $email,
        ?string $organizationId = null,
        ?string $roleSlug = null,
        ?int $expiresInDays = null,
        ?string $inviterUserId = null,
        ?CreateUserInviteOptionsLocale $locale = null,
        ?string $idempotencyKey = null,
    ): UserInvite {
        return $this->clients->client()->userManagement()->sendInvitation(
            email: $email,
            organizationId: $organizationId,
            roleSlug: $roleSlug,
            expiresInDays: $expiresInDays,
            inviterUserId: $inviterUserId,
            locale: $locale,
            options: $idempotencyKey !== null ? new RequestOptions(idempotencyKey: $idempotencyKey) : null,
        );
    }

    public function get(string $id): UserInvite
    {
        return $this->clients->client()->userManagement()->getInvitation($id);
    }

    public function findByToken(string $token): UserInvite
    {
        return $this->clients->client()->userManagement()->findInvitationByToken($token);
    }

    public function resend(string $id, ?CreateUserInviteOptionsLocale $locale = null): UserInvite
    {
        return $this->clients->client()->userManagement()->resendInvitation($id, $locale);
    }

    public function revoke(string $id): Invitation
    {
        return $this->clients->client()->userManagement()->revokeInvitation($id);
    }

    public function accept(string $id): Invitation
    {
        return $this->clients->client()->userManagement()->acceptInvitation($id);
    }

    /**
     * Trivial — the SDK already returns the accept URL on the resource; this
     * exists so callers never build the URL by hand.
     */
    public function acceptUrl(Invitation|UserInvite $invitation): string
    {
        return $invitation->acceptInvitationUrl;
    }
}
