<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Facades;

use Illuminate\Support\Facades\Facade;
use WorkOS\AuthKit\Auth\ApiKeyValidation;
use WorkOS\AuthKit\Auth\WorkOSSession;
use WorkOS\AuthKit\FeatureFlags\FeatureFlagService;
use WorkOS\AuthKit\FGA\FGAService;
use WorkOS\AuthKit\Services\DomainService;
use WorkOS\AuthKit\Services\PipesService;
use WorkOS\AuthKit\Services\RadarService;
use WorkOS\AuthKit\Services\VaultService;

/**
 * Convenience methods
 *
 * @method static \Illuminate\Contracts\Auth\Authenticatable|null user()
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
 * @method static ApiKeyValidation|null validateApiKey(string $key)
 * @method static WorkOSSession storeSession(array<string, mixed> $authResponse)
 * @method static void destroySession()
 * @method static void audit(string $action, array<int, mixed> $targets = [], array<string, mixed> $metadata = [])
 *
 * SDK service accessors
 * @method static \WorkOS\Service\UserManagement userManagement()
 * @method static \WorkOS\Service\Organizations organizations()
 * @method static \WorkOS\Service\SSO sso()
 * @method static \WorkOS\Service\DirectorySync directorySync()
 * @method static \WorkOS\Service\AuditLogs auditLogs()
 * @method static \WorkOS\WebhookVerification webhookVerification()
 * @method static \WorkOS\Service\Webhooks webhooks()
 * @method static \WorkOS\SessionManager sdkSessionManager()
 * @method static \WorkOS\Service\FeatureFlags featureFlags()
 * @method static \WorkOS\Service\ApiKeys apiKeys()
 * @method static \WorkOS\Service\Connect connect()
 * @method static \WorkOS\Service\Events events()
 * @method static \WorkOS\Service\OrganizationDomains organizationDomains()
 * @method static \WorkOS\Service\Pipes sdkPipes()
 * @method static \WorkOS\Service\Radar sdkRadar()
 * @method static \WorkOS\Vault sdkVault()
 * @method static \WorkOS\Actions actions()
 * @method static \WorkOS\PKCEHelper pkce()
 * @method static \WorkOS\Service\MultiFactorAuth multiFactorAuth()
 * @method static \WorkOS\Service\AdminPortal adminPortal()
 * @method static \WorkOS\Service\Authorization authorization()
 * @method static \WorkOS\Passwordless passwordless()
 * @method static \WorkOS\Service\Widgets widgets()
 *
 * Feature-gated sub-services
 * @method static FeatureFlagService flags()
 * @method static FGAService fga()
 * @method static VaultService vault()
 * @method static RadarService radar()
 * @method static PipesService pipes()
 * @method static DomainService domains()
 *
 * Testing
 * @method static \WorkOS\AuthKit\Testing\WorkOSFake fake()
 * @method static \WorkOS\AuthKit\Testing\WorkOSFake actingAs(\Illuminate\Contracts\Auth\Authenticatable $user, array<string> $roles = [], array<string> $permissions = [], ?string $organizationId = null, array<string> $featureFlags = [], array<string> $entitlements = [])
 * @method static bool isFaked()
 * @method static void restore()
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
