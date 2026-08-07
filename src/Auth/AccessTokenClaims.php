<?php

declare(strict_types=1);

namespace Authkit\Authkit\Auth;

use InvalidArgumentException;

final readonly class AccessTokenClaims
{
    /**
     * Claims the guard cannot function without: identity, issuer, the
     * audience-equivalent, the session ID the refresh lock is keyed on, and
     * expiry. A token missing any of them is malformed rather than sparsely
     * populated. `jti` and `iat` are deliberately NOT here — they are only
     * carried, never acted on, and their presence in a default AuthKit token is
     * still unconfirmed (docs/token-audit-findings.md), so requiring them would
     * turn a template quirk into a full lockout.
     */
    private const array REQUIRED_CLAIMS = ['sub', 'iss', 'client_id', 'sid', 'exp'];

    /**
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     * @param  list<string>  $featureFlags
     */
    public function __construct(
        public string $sub,
        public string $iss,
        public string $clientId,
        public ?string $organizationId,
        public ?string $role,
        public array $roles,
        public array $permissions,
        public array $featureFlags,
        public string $sessionId,
        public string $jwtId,
        public int $issuedAt,
        public int $expiresAt,
        public ?string $actorId,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        foreach (self::REQUIRED_CLAIMS as $claim) {
            if (! array_key_exists($claim, $payload)) {
                throw new InvalidArgumentException("Malformed access token: missing required claim '{$claim}'.");
            }
        }

        $actor = $payload['act'] ?? null;

        return new self(
            sub: self::string($payload['sub']),
            iss: self::string($payload['iss']),
            clientId: self::string($payload['client_id']),
            organizationId: self::nullableString($payload['org_id'] ?? null),
            role: self::nullableString($payload['role'] ?? null),
            roles: self::stringList($payload['roles'] ?? null),
            permissions: self::stringList($payload['permissions'] ?? null),
            featureFlags: self::stringList($payload['feature_flags'] ?? null),
            sessionId: self::string($payload['sid']),
            jwtId: self::string($payload['jti'] ?? ''),
            issuedAt: self::int($payload['iat'] ?? 0),
            expiresAt: self::int($payload['exp']),
            actorId: is_array($actor) ? self::nullableString($actor['sub'] ?? null) : null,
        );
    }

    public function isImpersonated(): bool
    {
        return $this->actorId !== null;
    }

    public function secondsUntilExpiry(): int
    {
        return $this->expiresAt - time();
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(self::string(...), array_filter($value, is_scalar(...))));
    }
}
