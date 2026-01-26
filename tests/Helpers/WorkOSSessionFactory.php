<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Tests\Helpers;

use Carbon\Carbon;
use WorkOS\AuthKit\Auth\WorkOSSession;

final class WorkOSSessionFactory
{
    /**
     * Create a test session with optional overrides.
     *
     * @param  array<string>  $roles
     * @param  array<string>  $permissions
     * @param  array<string, mixed>|null  $impersonator
     */
    public static function create(
        array $roles = [],
        array $permissions = [],
        ?string $organizationId = null,
        ?array $impersonator = null,
        string $userId = 'user_123',
        string $accessToken = 'token_abc',
        ?string $refreshToken = null,
        ?Carbon $expiresAt = null,
        string $sessionId = 'session_456',
    ): WorkOSSession {
        return new WorkOSSession(
            userId: $userId,
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: $expiresAt ?? Carbon::now()->addHour(),
            sessionId: $sessionId,
            roles: $roles,
            permissions: $permissions,
            organizationId: $organizationId,
            impersonator: $impersonator,
        );
    }

    /**
     * Create a session with admin role.
     */
    public static function admin(?string $organizationId = null): WorkOSSession
    {
        return self::create(roles: ['admin'], organizationId: $organizationId);
    }

    /**
     * Create a session with specific roles.
     *
     * @param  array<string>  $roles
     */
    public static function withRoles(array $roles, ?string $organizationId = null): WorkOSSession
    {
        return self::create(roles: $roles, organizationId: $organizationId);
    }

    /**
     * Create a session with specific permissions.
     *
     * @param  array<string>  $permissions
     */
    public static function withPermissions(array $permissions, ?string $organizationId = null): WorkOSSession
    {
        return self::create(permissions: $permissions, organizationId: $organizationId);
    }

    /**
     * Create a session with impersonation.
     *
     * @param  array<string, mixed>  $impersonator
     */
    public static function impersonating(array $impersonator = ['email' => 'admin@example.com']): WorkOSSession
    {
        return self::create(impersonator: $impersonator);
    }

    /**
     * Create an expired session.
     */
    public static function expired(): WorkOSSession
    {
        return self::create(expiresAt: Carbon::now()->subHour());
    }
}
