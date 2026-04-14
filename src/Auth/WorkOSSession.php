<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Auth;

use Carbon\Carbon;
use SensitiveParameter;

readonly class WorkOSSession
{
    /**
     * @param  array<string>  $roles
     * @param  array<string>  $permissions
     * @param  array<string>  $featureFlags
     * @param  array<string>  $entitlements
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>|null  $impersonator
     */
    public function __construct(
        public string $userId,
        #[SensitiveParameter]
        public string $accessToken,
        #[SensitiveParameter]
        public ?string $refreshToken,
        public Carbon $expiresAt,
        public ?string $sessionId,
        public array $roles,
        public array $permissions,
        public array $featureFlags = [],
        public array $entitlements = [],
        public ?string $organizationId = null,
        public ?array $impersonator = null,
        public array $claims = [],
    ) {}

    /**
     * @param  array<string, mixed>  $response
     */
    public static function fromAuthResponse(#[SensitiveParameter] array $response): self
    {
        /** @var array<string, mixed> $user */
        $user = $response['user'] ?? [];

        // Handle expiry - API may return expires_in (seconds) or expires_at (timestamp)
        if (isset($response['expires_at'])) {
            $expiresAt = Carbon::parse((string) $response['expires_at']);
        } elseif (isset($response['expires_in'])) {
            $expiresAt = Carbon::now()->addSeconds((int) $response['expires_in']);
        } else {
            // Default to 1 hour if no expiry info provided
            $expiresAt = Carbon::now()->addHour();
        }

        return new self(
            userId: (string) ($user['id'] ?? ''),
            accessToken: (string) ($response['access_token'] ?? ''),
            refreshToken: isset($response['refresh_token']) ? (string) $response['refresh_token'] : null,
            expiresAt: $expiresAt,
            sessionId: isset($response['session_id']) ? (string) $response['session_id'] : null,
            roles: isset($user['roles']) && is_array($user['roles']) ? $user['roles'] : [],
            permissions: isset($user['permissions']) && is_array($user['permissions']) ? $user['permissions'] : [],
            featureFlags: isset($response['feature_flags']) && is_array($response['feature_flags']) ? $response['feature_flags'] : [],
            entitlements: isset($response['entitlements']) && is_array($response['entitlements']) ? $response['entitlements'] : [],
            organizationId: isset($response['organization_id']) ? (string) $response['organization_id'] : null,
            impersonator: isset($response['impersonator']) && is_array($response['impersonator']) ? $response['impersonator'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(#[SensitiveParameter] array $data): self
    {
        return new self(
            userId: (string) $data['user_id'],
            accessToken: (string) $data['access_token'],
            refreshToken: isset($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            expiresAt: Carbon::parse((string) $data['expires_at']),
            sessionId: isset($data['session_id']) ? (string) $data['session_id'] : null,
            roles: isset($data['roles']) && is_array($data['roles']) ? $data['roles'] : [],
            permissions: isset($data['permissions']) && is_array($data['permissions']) ? $data['permissions'] : [],
            featureFlags: isset($data['feature_flags']) && is_array($data['feature_flags']) ? $data['feature_flags'] : [],
            entitlements: isset($data['entitlements']) && is_array($data['entitlements']) ? $data['entitlements'] : [],
            organizationId: isset($data['organization_id']) ? (string) $data['organization_id'] : null,
            impersonator: isset($data['impersonator']) && is_array($data['impersonator']) ? $data['impersonator'] : null,
            claims: isset($data['claims']) && is_array($data['claims']) ? $data['claims'] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt->toIso8601String(),
            'session_id' => $this->sessionId,
            'roles' => $this->roles,
            'permissions' => $this->permissions,
            'feature_flags' => $this->featureFlags,
            'entitlements' => $this->entitlements,
            'organization_id' => $this->organizationId,
            'impersonator' => $this->impersonator,
            'claims' => $this->claims,
        ];
    }

    public function isExpired(): bool
    {
        return $this->expiresAt->isPast();
    }

    public function needsRefresh(int $bufferMinutes): bool
    {
        return $this->expiresAt->subMinutes($bufferMinutes)->isPast();
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function hasFeatureFlag(string $flag): bool
    {
        return in_array($flag, $this->featureFlags, true);
    }

    public function hasEntitlement(string $entitlement): bool
    {
        return in_array($entitlement, $this->entitlements, true);
    }

    public function claim(string $key, mixed $default = null): mixed
    {
        return $this->claims[$key] ?? $default;
    }
}
