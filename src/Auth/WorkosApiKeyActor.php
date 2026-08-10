<?php

declare(strict_types=1);

namespace Authkit\Authkit\Auth;

use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The principal for an organization-scoped API key: there is no natural user
 * to "be" for an org-owned key, so the guard returns this synthetic
 * Authenticatable wrapping the local organization projection, the key's
 * permissions, and the key's identity. Stateless by construction — nothing
 * here persists, remembers, or has a password.
 */
final class WorkosApiKeyActor implements Authenticatable
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public readonly Model $organization,
        public readonly array $permissions,
        public readonly string $apiKeyId,
        public readonly ?DateTimeImmutable $expiresAt = null,
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'api_key_id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->apiKeyId;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken(mixed $value): void
    {
        // Stateless actor — no remember-me concept for API keys.
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
