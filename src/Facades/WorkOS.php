<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Facades;

use Illuminate\Support\Facades\Facade;
use WorkOS\AuthKit\Auth\ApiKeyValidation;
use WorkOS\AuthKit\Auth\WorkOSSession;
use WorkOS\AuthKit\FGA\FGAService;
use WorkOS\AuthKit\FeatureFlags\FeatureFlagService;
use WorkOS\AuthKit\Services\DomainService;
use WorkOS\AuthKit\Services\PipesService;
use WorkOS\AuthKit\Services\RadarService;
use WorkOS\AuthKit\Services\VaultService;

/**
 * @method static \Illuminate\Contracts\Auth\Authenticatable|null user()
 * @method static ApiKeyValidation|null validateApiKey(string $key)
 * @method static FeatureFlagService flags()
 * @method static FGAService fga()
 * @method static VaultService vault()
 * @method static RadarService radar()
 * @method static PipesService pipes()
 * @method static DomainService domains()
 * @method static WorkOSSession|null session()
 * @method static WorkOSSession|null validSession()
 * @method static string loginUrl(?string $organizationId = null, ?array<string, mixed> $state = null, ?string $screenHint = null, ?string $loginHint = null)
 * @method static string signUpUrl(?string $organizationId = null, ?array<string, mixed> $state = null)
 * @method static string|null getLogoutUrl(?string $returnTo = null)
 * @method static bool isAuthenticated()
 * @method static bool isImpersonating()
 * @method static bool hasPermission(string $permission)
 * @method static bool hasRole(string $role)
 * @method static bool hasFeatureFlag(string $flag)
 * @method static bool hasEntitlement(string $entitlement)
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
