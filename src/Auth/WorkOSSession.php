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
        public ?string $organizationId,
        public ?array $impersonator,
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
            organizationId: isset($data['organization_id']) ? (string) $data['organization_id'] : null,
            impersonator: isset($data['impersonator']) && is_array($data['impersonator']) ? $data['impersonator'] : null,
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
            'organization_id' => $this->organizationId,
            'impersonator' => $this->impersonator,
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
}
