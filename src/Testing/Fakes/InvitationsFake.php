<?php

declare(strict_types=1);

namespace Authkit\Authkit\Testing\Fakes;

use Authkit\Authkit\Invitations\InvitationManager;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use WorkOS\PaginatedResponse;
use WorkOS\Resource\CreateUserInviteOptionsLocale;
use WorkOS\Resource\Invitation;
use WorkOS\Resource\UserInvite;
use WorkOS\Resource\UserInviteState;

/**
 * An in-memory {@see InvitationManager}: a stateful registry instead of the
 * WorkOS API. send() mints a pending invitation, revoke()/accept() transition
 * its state, and list()/get()/findByToken() read the registry back — so a
 * full invite flow can be exercised in one test with zero network.
 */
final class InvitationsFake extends InvitationManager
{
    /** @var array<string, UserInvite> */
    private array $invitations = [];

    /** @var list<UserInvite> invitations created by send(), oldest first */
    private array $sent = [];

    /** @var list<string> invitation ids passed to resend() */
    private array $resent = [];

    /** @var list<string> invitation ids passed to revoke() */
    private array $revoked = [];

    /** @var list<string> invitation ids passed to accept() */
    private array $accepted = [];

    private int $sequence = 0;

    public function __construct()
    {
        // Deliberately no parent call: the fake never touches the WorkOS
        // client, and every inherited method that would is overridden below.
    }

    /**
     * Put an invitation into the registry without recording a send — fixture
     * state for tests exercising get()/findByToken()/accept() flows.
     *
     * @param  array<string, mixed>  $attributes  raw resource attributes, snake_case as the API returns them
     */
    public function seed(array $attributes = []): UserInvite
    {
        $invitation = $this->makeInvitation(
            is_string($attributes['email'] ?? null) ? $attributes['email'] : 'invitee@example.com',
            $attributes,
        );

        $this->invitations[$invitation->id] = $invitation;

        return $invitation;
    }

    public function send(
        string $email,
        ?string $organizationId = null,
        ?string $roleSlug = null,
        ?int $expiresInDays = null,
        ?string $inviterUserId = null,
        ?CreateUserInviteOptionsLocale $locale = null,
        ?string $idempotencyKey = null,
    ): UserInvite {
        $invitation = $this->makeInvitation($email, array_filter([
            'organization_id' => $organizationId,
            'role_slug' => $roleSlug,
            'inviter_user_id' => $inviterUserId,
            'expires_at' => $expiresInDays !== null
                ? (new DateTimeImmutable("+{$expiresInDays} days"))->format(DateTimeInterface::RFC3339_EXTENDED)
                : null,
        ], static fn (mixed $value): bool => $value !== null));

        $this->invitations[$invitation->id] = $invitation;
        $this->sent[] = $invitation;

        return $invitation;
    }

    public function get(string $id): UserInvite
    {
        return $this->invitations[$id] ?? throw new InvalidArgumentException(
            "No fake invitation [{$id}] exists. Create one first with send() or seed().",
        );
    }

    public function findByToken(string $token): UserInvite
    {
        foreach ($this->invitations as $invitation) {
            if ($invitation->token === $token) {
                return $invitation;
            }
        }

        throw new InvalidArgumentException(
            "No fake invitation carries the token [{$token}]. Create one first with send() or seed().",
        );
    }

    public function resend(string $id, ?CreateUserInviteOptionsLocale $locale = null): UserInvite
    {
        $invitation = $this->get($id);

        $this->resent[] = $id;

        return $invitation;
    }

    public function revoke(string $id): Invitation
    {
        $invitation = $this->transition($id, UserInviteState::Revoked, 'revoked_at');

        $this->revoked[] = $id;

        return $invitation;
    }

    public function accept(string $id): Invitation
    {
        $invitation = $this->transition($id, UserInviteState::Accepted, 'accepted_at');

        $this->accepted[] = $id;

        return $invitation;
    }

    public function list(
        ?string $organizationId = null,
        ?string $email = null,
        ?string $before = null,
        ?string $after = null,
        ?int $limit = null,
    ): PaginatedResponse {
        $matches = array_values(array_filter(
            $this->invitations,
            static fn (UserInvite $invitation): bool => ($organizationId === null || $invitation->organizationId === $organizationId)
                && ($email === null || $invitation->email === $email),
        ));

        return new PaginatedResponse($matches, []);
    }

    /**
     * @param  (callable(UserInvite): bool)|null  $callback
     */
    public function assertSent(string $email, ?callable $callback = null): void
    {
        $matches = array_filter(
            $this->sent,
            static fn (UserInvite $invitation): bool => $invitation->email === $email
                && ($callback === null || $callback($invitation)),
        );

        Assert::assertNotEmpty($matches, sprintf(
            'Expected an invitation sent to [%s]%s but none matched. %s',
            $email,
            $callback !== null ? ' passing the given callback' : '',
            $this->describeSends(),
        ));
    }

    public function assertNothingSent(): void
    {
        Assert::assertEmpty($this->sent, sprintf('Expected no invitations to be sent. %s', $this->describeSends()));
    }

    public function assertResent(string $id): void
    {
        Assert::assertContains($id, $this->resent, "Expected invitation [{$id}] to be resent, but it was not.");
    }

    public function assertRevoked(string $id): void
    {
        Assert::assertContains($id, $this->revoked, "Expected invitation [{$id}] to be revoked, but it was not.");
    }

    public function assertAccepted(string $id): void
    {
        Assert::assertContains($id, $this->accepted, "Expected invitation [{$id}] to be accepted, but it was not.");
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeInvitation(string $email, array $attributes = []): UserInvite
    {
        $id = 'invitation_fake_'.++$this->sequence;
        $token = 'invite_token_'.$this->sequence;
        $now = (new DateTimeImmutable)->format(DateTimeInterface::RFC3339_EXTENDED);

        return UserInvite::fromArray(array_merge([
            'object' => 'invitation',
            'id' => $id,
            'email' => $email,
            'state' => UserInviteState::Pending->value,
            'expires_at' => (new DateTimeImmutable('+7 days'))->format(DateTimeInterface::RFC3339_EXTENDED),
            'created_at' => $now,
            'updated_at' => $now,
            'token' => $token,
            'accept_invitation_url' => "https://fake.workos.test/invite/{$token}",
        ], $attributes));
    }

    private function transition(string $id, UserInviteState $state, string $timestampKey): Invitation
    {
        $current = $this->get($id)->toArray();

        $current['state'] = $state->value;
        $current[$timestampKey] = (new DateTimeImmutable)->format(DateTimeInterface::RFC3339_EXTENDED);

        $this->invitations[$id] = UserInvite::fromArray($current);

        return Invitation::fromArray($current);
    }

    private function describeSends(): string
    {
        if ($this->sent === []) {
            return 'No invitations were sent.';
        }

        $emails = array_map(static fn (UserInvite $invitation): string => $invitation->email, $this->sent);

        return 'Sent to: '.implode(', ', $emails).'.';
    }
}
