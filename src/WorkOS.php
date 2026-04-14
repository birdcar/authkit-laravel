<?php

declare(strict_types=1);

namespace WorkOS\AuthKit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use WorkOS\Actions;
use WorkOS\AuthKit\Audit\AuditLogger;
use WorkOS\AuthKit\Auth\ApiKeyValidation;
use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\AuthKit\Auth\WorkOSSession;
use WorkOS\AuthKit\FeatureFlags\FeatureFlagService;
use WorkOS\AuthKit\FGA\FGAService;
use WorkOS\AuthKit\Services\DomainService;
use WorkOS\AuthKit\Services\PipesService;
use WorkOS\AuthKit\Services\RadarService;
use WorkOS\AuthKit\Services\VaultService;
use WorkOS\AuthKit\Testing\WorkOSFake;
use WorkOS\Passwordless;
use WorkOS\PKCEHelper;
use WorkOS\Resource\UserManagementAuthenticationProvider;
use WorkOS\Service\AdminPortal;
use WorkOS\Service\ApiKeys;
use WorkOS\Service\AuditLogs;
use WorkOS\Service\Authorization;
use WorkOS\Service\Connect;
use WorkOS\Service\DirectorySync;
use WorkOS\Service\Events;
use WorkOS\Service\FeatureFlags;
use WorkOS\Service\MultiFactorAuth;
use WorkOS\Service\OrganizationDomains;
use WorkOS\Service\Organizations;
use WorkOS\Service\Pipes;
use WorkOS\Service\Radar;
use WorkOS\Service\SSO;
use WorkOS\Service\UserManagement;
use WorkOS\Service\Webhooks;
use WorkOS\Service\Widgets;
use WorkOS\Vault;
use WorkOS\WebhookVerification;

class WorkOS
{
    /** @var WorkOSFake|null */
    private static $fake = null;

    public function __construct(
        private readonly \WorkOS\WorkOS $client,
        private readonly SessionManager $session,
    ) {}

    // SDK Service Accessors

    public function userManagement(): UserManagement
    {
        return $this->client->userManagement();
    }

    public function organizations(): Organizations
    {
        return $this->client->organizations();
    }

    public function sso(): SSO
    {
        return $this->client->sso();
    }

    public function directorySync(): DirectorySync
    {
        return $this->client->directorySync();
    }

    public function auditLogs(): AuditLogs
    {
        return $this->client->auditLogs();
    }

    public function webhookVerification(): WebhookVerification
    {
        return $this->client->webhookVerification();
    }

    public function webhooks(): Webhooks
    {
        return $this->client->webhooks();
    }

    public function sdkSessionManager(): \WorkOS\SessionManager
    {
        return $this->client->sessionManager();
    }

    public function featureFlags(): FeatureFlags
    {
        return $this->client->featureFlags();
    }

    public function apiKeys(): ApiKeys
    {
        return $this->client->apiKeys();
    }

    public function connect(): Connect
    {
        return $this->client->connect();
    }

    public function events(): Events
    {
        return $this->client->events();
    }

    public function organizationDomains(): OrganizationDomains
    {
        return $this->client->organizationDomains();
    }

    public function sdkPipes(): Pipes
    {
        return $this->client->pipes();
    }

    public function sdkRadar(): Radar
    {
        return $this->client->radar();
    }

    public function sdkVault(): Vault
    {
        return $this->client->vault();
    }

    public function actions(): Actions
    {
        return $this->client->actions();
    }

    public function pkce(): PKCEHelper
    {
        return $this->client->pkce();
    }

    public function multiFactorAuth(): MultiFactorAuth
    {
        return $this->client->multiFactorAuth();
    }

    public function adminPortal(): AdminPortal
    {
        return $this->client->adminPortal();
    }

    public function authorization(): Authorization
    {
        return $this->client->authorization();
    }

    public function passwordless(): Passwordless
    {
        return $this->client->passwordless();
    }

    public function widgets(): Widgets
    {
        return $this->client->widgets();
    }

    // Feature-gated sub-services

    public function flags(): FeatureFlagService
    {
        return app(FeatureFlagService::class);
    }

    public function fga(): FGAService
    {
        return app(FGAService::class);
    }

    public function vault(): VaultService
    {
        if (! config('workos.features.vault', false)) {
            throw new \RuntimeException('WorkOS Vault is not enabled. Set WORKOS_FEATURE_VAULT=true in your .env.');
        }

        return new VaultService($this->client);
    }

    public function radar(): RadarService
    {
        if (! config('workos.features.radar', false)) {
            throw new \RuntimeException('WorkOS Radar is not enabled. Set WORKOS_FEATURE_RADAR=true in your .env.');
        }

        return new RadarService($this->client);
    }

    public function pipes(): PipesService
    {
        if (! config('workos.features.pipes', false)) {
            throw new \RuntimeException('WorkOS Pipes is not enabled. Set WORKOS_FEATURE_PIPES=true in your .env.');
        }

        return new PipesService($this->client);
    }

    public function domains(): DomainService
    {
        if (! config('workos.features.domain_verification', false)) {
            throw new \RuntimeException('WorkOS Domain Verification is not enabled. Set WORKOS_FEATURE_DOMAIN_VERIFICATION=true in your .env.');
        }

        return new DomainService($this->client);
    }

    // Convenience methods

    public function user(): ?Authenticatable
    {
        /** @var Guard $guard */
        $guard = auth(config('workos.guard', 'workos'));

        return $guard->user();
    }

    public function session(): ?WorkOSSession
    {
        return $this->session->getSession();
    }

    public function validSession(): ?WorkOSSession
    {
        return $this->session->getValidSession();
    }

    /**
     * @param  array<string, mixed>|null  $state
     */
    public function loginUrl(
        ?string $organizationId = null,
        ?array $state = null,
        ?string $screenHint = null,
        ?string $loginHint = null,
    ): string {
        $stateStr = $state !== null ? json_encode($state) : null;

        $params = array_filter([
            'client_id' => config('workos.client_id'),
            'redirect_uri' => (string) config('workos.redirect_uri'),
            'response_type' => 'code',
            'provider' => UserManagementAuthenticationProvider::Authkit->value,
            'state' => $stateStr !== false ? $stateStr : null,
            'organization_id' => $organizationId,
            'login_hint' => $loginHint,
            'screen_hint' => $screenHint,
        ], fn ($v) => $v !== null);

        return 'https://api.workos.com/user_management/authorize?'.http_build_query($params);
    }

    /**
     * @param  array<string, mixed>|null  $state
     */
    public function signUpUrl(?string $organizationId = null, ?array $state = null): string
    {
        return $this->loginUrl(
            organizationId: $organizationId,
            state: $state,
            screenHint: 'sign-up',
        );
    }

    public function getLogoutUrl(?string $returnTo = null): ?string
    {
        return $this->session->getLogoutUrl($returnTo);
    }

    public function isAuthenticated(): bool
    {
        return $this->validSession() !== null;
    }

    public function isImpersonating(): bool
    {
        return $this->session->isImpersonating();
    }

    public function hasPermission(string $permission): bool
    {
        return $this->session->hasPermission($permission);
    }

    public function hasRole(string $role): bool
    {
        return $this->session->hasRole($role);
    }

    public function hasFeatureFlag(string $flag): bool
    {
        return $this->validSession()?->hasFeatureFlag($flag) ?? false;
    }

    public function hasEntitlement(string $entitlement): bool
    {
        return $this->validSession()?->hasEntitlement($entitlement) ?? false;
    }

    public function validateApiKey(string $key): ?ApiKeyValidation
    {
        try {
            $result = $this->client->apiKeys()->createValidations(value: $key);

            if ($result->apiKey === null) {
                return null;
            }

            return ApiKeyValidation::fromResponse($result->apiKey->toArray());
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $authResponse
     */
    public function storeSession(#[\SensitiveParameter] array $authResponse): WorkOSSession
    {
        return $this->session->store($authResponse);
    }

    public function destroySession(): void
    {
        $this->session->destroy();
    }

    /**
     * @param  array<int, mixed>  $targets
     * @param  array<string, mixed>  $metadata
     */
    public function audit(string $action, array $targets = [], array $metadata = []): void
    {
        app(AuditLogger::class)->log($action, $targets, metadata: $metadata);
    }

    // Testing helpers

    public static function fake(): WorkOSFake
    {
        self::$fake = new WorkOSFake;
        app()->instance('workos', self::$fake);
        Facades\WorkOS::clearResolvedInstance('workos');

        return self::$fake;
    }

    /**
     * @param  array<string>  $roles
     * @param  array<string>  $permissions
     * @param  array<string>  $featureFlags
     * @param  array<string>  $entitlements
     */
    public static function actingAs(
        Authenticatable $user,
        array $roles = [],
        array $permissions = [],
        ?string $organizationId = null,
        array $featureFlags = [],
        array $entitlements = [],
    ): WorkOSFake {
        return static::fake()->actingAs($user, $roles, $permissions, $organizationId, $featureFlags, $entitlements);
    }

    public static function isFaked(): bool
    {
        return self::$fake !== null;
    }

    public static function restore(): void
    {
        self::$fake = null;
        app()->forgetInstance('workos');
        Facades\WorkOS::clearResolvedInstance('workos');
    }
}
