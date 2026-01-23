<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Facades;

use Illuminate\Support\Facades\Facade;
use WorkOS\AuthKit\Auth\WorkOSSession;

/**
 * @method static \Illuminate\Contracts\Auth\Authenticatable|null user()
 * @method static WorkOSSession|null session()
 * @method static WorkOSSession|null validSession()
 * @method static string loginUrl(?string $organizationId = null, ?array<string, mixed> $state = null)
 * @method static string logoutUrl(?string $returnTo = null)
 * @method static bool isAuthenticated()
 * @method static bool isImpersonating()
 * @method static bool hasPermission(string $permission)
 * @method static bool hasRole(string $role)
 * @method static WorkOSSession storeSession(array<string, mixed> $authResponse)
 * @method static void destroySession()
 * @method static \WorkOS\AuditLogs auditLogs()
 * @method static \WorkOS\DirectorySync directorySync()
 * @method static \WorkOS\MFA mfa()
 * @method static \WorkOS\Organizations organizations()
 * @method static \WorkOS\Passwordless passwordless()
 * @method static \WorkOS\Portal portal()
 * @method static \WorkOS\SSO sso()
 * @method static \WorkOS\UserManagement userManagement()
 * @method static \WorkOS\Webhook webhook()
 *
 * @see \WorkOS\AuthKit\WorkOS
 */
class WorkOS extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'workos';
    }
}
