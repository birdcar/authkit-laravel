<?php

declare(strict_types=1);

namespace Authkit\Authkit\Testing;

use Authkit\Authkit\Auth\WorkosGuard;
use Authkit\Authkit\Contracts\HasAccessTokenClaims;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;

/**
 * The `workos` guard as installed by {@see FakesWorkosSession::actingAs()}: a
 * principal and its claims, held directly, with no cookie, no JWKS fetch and
 * no SDK call.
 *
 * This is a separate class rather than a seeded {@see WorkosGuard}
 * for two reasons, both load-bearing:
 *
 * 1. The real guard's `claimsPayload` is populated only while unsealing a
 *    cookie, so `setUser()` alone yields a principal whose
 *    `accessTokenClaims()` is null — and ClaimsGateHook, the Pennant driver,
 *    CurrentOrganizationResolver, FgaChecker and AuditActorResolver all read
 *    claims from the *guard*, not from the user model. A seeded real guard
 *    authenticates but has no permissions, no org and no feature flags.
 *
 * 2. The real guard is rebuilt-and-reset on every `request` rebind (the
 *    provider's `refresh('request', $guard, 'setRequest')`), which silently
 *    erases a principal seeded before `$this->get(...)`. This guard has no
 *    such binding, so a session set up before the HTTP call survives into it.
 */
final class FakeWorkosGuard implements Guard, HasAccessTokenClaims
{
    /**
     * @param  array<string, mixed>|null  $claims
     */
    public function __construct(
        private ?Authenticatable $user = null,
        private ?array $claims = null,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function accessTokenClaims(): ?array
    {
        return $this->user !== null ? $this->claims : null;
    }

    public function check(): bool
    {
        return $this->user !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function user(): ?Authenticatable
    {
        return $this->user;
    }

    public function id(): int|string|null
    {
        $identifier = $this->user?->getAuthIdentifier();

        return is_int($identifier) || is_string($identifier) ? $identifier : null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function validate(array $credentials = []): bool
    {
        // Matches the real guard: this guard authenticates a session, never
        // raw credentials.
        return false;
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    /**
     * Sets the principal without touching claims — mirrors the real guard's
     * `setUser()`. Prefer `Authkit::actingAs()`, which sets both.
     */
    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;

        return $this;
    }
}
