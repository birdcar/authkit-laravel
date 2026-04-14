<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Testing;

use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use PHPUnit\Framework\Assert;
use WorkOS\Actions;
use WorkOS\AuthKit\Auth\ApiKeyValidation;
use WorkOS\AuthKit\Auth\WorkOSSession;
use WorkOS\AuthKit\FeatureFlags\FeatureFlagService;
use WorkOS\AuthKit\FGA\FGAService;
use WorkOS\AuthKit\Services\DomainService;
use WorkOS\AuthKit\Services\PipesService;
use WorkOS\AuthKit\Services\RadarService;
use WorkOS\AuthKit\Services\VaultService;
use WorkOS\Passwordless;
use WorkOS\PKCEHelper;
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
use WorkOS\SessionManager;
use WorkOS\Vault;
use WorkOS\WebhookVerification;
use WorkOS\WorkOS;

class WorkOSFake
{
    private ?Authenticatable $user = null;

    /** @var array<string> */
    private array $roles = [];

    /** @var array<string> */
    private array $permissions = [];

    /** @var array<string> */
    private array $featureFlags = [];

    /** @var array<string> */
    private array $entitlements = [];

    private ?string $organizationId = null;

    /** @var array<string, mixed>|null */
    private ?array $impersonator = null;

    /** @var array<int, array{action: string, targets: array<int, mixed>, metadata: array<string, mixed>}> */
    private array $auditedEvents = [];

    /**
     * @param  array<string>  $roles
     * @param  array<string>  $permissions
     * @param  array<string>  $featureFlags
     * @param  array<string>  $entitlements
     */
    public function actingAs(
        Authenticatable $user,
        array $roles = [],
        array $permissions = [],
        ?string $organizationId = null,
        array $featureFlags = [],
        array $entitlements = [],
    ): static {
        $this->user = $user;
        $this->roles = $roles;
        $this->permissions = $permissions;
        $this->featureFlags = $featureFlags;
        $this->entitlements = $entitlements;
        $this->organizationId = $organizationId;

        $session = $this->buildSession();

        if (method_exists($user, 'setWorkOSSession')) {
            $user->setWorkOSSession($session);
        }

        /** @var Guard $guard */
        $guard = auth(config('workos.guard', 'workos'));
        $guard->setUser($user);

        return $this;
    }

    /**
     * @param  array<string>  $roles
     */
    public function withRoles(array $roles): static
    {
        $this->roles = array_merge($this->roles, $roles);
        $this->refreshSession();

        return $this;
    }

    /**
     * @param  array<string>  $permissions
     */
    public function withPermissions(array $permissions): static
    {
        $this->permissions = array_merge($this->permissions, $permissions);
        $this->refreshSession();

        return $this;
    }

    /**
     * @param  array<string>  $featureFlags
     */
    public function withFeatureFlags(array $featureFlags): static
    {
        $this->featureFlags = array_merge($this->featureFlags, $featureFlags);
        $this->refreshSession();

        return $this;
    }

    /**
     * @param  array<string>  $entitlements
     */
    public function withEntitlements(array $entitlements): static
    {
        $this->entitlements = array_merge($this->entitlements, $entitlements);
        $this->refreshSession();

        return $this;
    }

    public function inOrganization(string $organizationId): static
    {
        $this->organizationId = $organizationId;
        $this->refreshSession();

        return $this;
    }

    /**
     * @param  array<string, mixed>  $impersonator
     */
    public function impersonating(array $impersonator): static
    {
        $this->impersonator = $impersonator;
        $this->refreshSession();

        return $this;
    }

    // Convenience methods

    public function user(): ?Authenticatable
    {
        return $this->user;
    }

    public function session(): ?WorkOSSession
    {
        return $this->user !== null ? $this->buildSession() : null;
    }

    public function validSession(): ?WorkOSSession
    {
        return $this->session();
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function hasFeatureFlag(string $flag): bool
    {
        return in_array($flag, $this->featureFlags, true);
    }

    public function hasEntitlement(string $entitlement): bool
    {
        return in_array($entitlement, $this->entitlements, true);
    }

    public function isAuthenticated(): bool
    {
        return $this->user !== null;
    }

    public function isImpersonating(): bool
    {
        return $this->impersonator !== null;
    }

    public function organizationId(): ?string
    {
        return $this->organizationId;
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
        return '/auth/login';
    }

    /**
     * @param  array<string, mixed>|null  $state
     */
    public function signUpUrl(?string $organizationId = null, ?array $state = null): string
    {
        return '/auth/login?screen_hint=sign-up';
    }

    public function getLogoutUrl(?string $returnTo = null): ?string
    {
        return $returnTo ?? '/';
    }

    public function validateApiKey(string $key): ?ApiKeyValidation
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $authResponse
     */
    public function storeSession(array $authResponse): WorkOSSession
    {
        return WorkOSSession::fromAuthResponse($authResponse);
    }

    public function destroySession(): void
    {
        $this->user = null;
        $this->roles = [];
        $this->permissions = [];
        $this->featureFlags = [];
        $this->entitlements = [];
        $this->organizationId = null;
        $this->impersonator = null;
    }

    /**
     * @param  array<int, mixed>  $targets
     * @param  array<string, mixed>  $metadata
     */
    public function audit(string $action, array $targets = [], array $metadata = []): void
    {
        $this->auditedEvents[] = compact('action', 'targets', 'metadata');
    }

    // SDK service accessor stubs — return the real SDK services via the container

    public function userManagement(): UserManagement
    {
        return app(WorkOS::class)->userManagement();
    }

    public function organizations(): Organizations
    {
        return app(WorkOS::class)->organizations();
    }

    public function sso(): SSO
    {
        return app(WorkOS::class)->sso();
    }

    public function directorySync(): DirectorySync
    {
        return app(WorkOS::class)->directorySync();
    }

    public function auditLogs(): AuditLogs
    {
        return app(WorkOS::class)->auditLogs();
    }

    public function webhookVerification(): WebhookVerification
    {
        return app(WorkOS::class)->webhookVerification();
    }

    public function webhooks(): Webhooks
    {
        return app(WorkOS::class)->webhooks();
    }

    public function sdkSessionManager(): SessionManager
    {
        return app(WorkOS::class)->sessionManager();
    }

    public function featureFlags(): FeatureFlags
    {
        return app(WorkOS::class)->featureFlags();
    }

    public function apiKeys(): ApiKeys
    {
        return app(WorkOS::class)->apiKeys();
    }

    public function connect(): Connect
    {
        return app(WorkOS::class)->connect();
    }

    public function events(): Events
    {
        return app(WorkOS::class)->events();
    }

    public function organizationDomains(): OrganizationDomains
    {
        return app(WorkOS::class)->organizationDomains();
    }

    public function sdkPipes(): Pipes
    {
        return app(WorkOS::class)->pipes();
    }

    public function sdkRadar(): Radar
    {
        return app(WorkOS::class)->radar();
    }

    public function sdkVault(): Vault
    {
        return app(WorkOS::class)->vault();
    }

    public function actions(): Actions
    {
        return app(WorkOS::class)->actions();
    }

    public function pkce(): PKCEHelper
    {
        return app(WorkOS::class)->pkce();
    }

    public function multiFactorAuth(): MultiFactorAuth
    {
        return app(WorkOS::class)->multiFactorAuth();
    }

    public function adminPortal(): AdminPortal
    {
        return app(WorkOS::class)->adminPortal();
    }

    public function authorization(): Authorization
    {
        return app(WorkOS::class)->authorization();
    }

    public function passwordless(): Passwordless
    {
        return app(WorkOS::class)->passwordless();
    }

    public function widgets(): Widgets
    {
        return app(WorkOS::class)->widgets();
    }

    // Feature-gated sub-service stubs

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
        return new VaultService(app(WorkOS::class));
    }

    public function radar(): RadarService
    {
        return new RadarService(app(WorkOS::class));
    }

    public function pipes(): PipesService
    {
        return new PipesService(app(WorkOS::class));
    }

    public function domains(): DomainService
    {
        return new DomainService(app(WorkOS::class));
    }

    public static function restore(): void
    {
        \WorkOS\AuthKit\WorkOS::restore();
    }

    // Assertions

    public function assertAuthenticated(): static
    {
        Assert::assertNotNull($this->user, 'Expected user to be authenticated.');

        return $this;
    }

    public function assertGuest(): static
    {
        Assert::assertNull($this->user, 'Expected no authenticated user.');

        return $this;
    }

    public function assertHasRole(string $role): static
    {
        Assert::assertTrue(
            $this->hasRole($role),
            "Expected user to have role [{$role}]."
        );

        return $this;
    }

    public function assertHasPermission(string $permission): static
    {
        Assert::assertTrue(
            $this->hasPermission($permission),
            "Expected user to have permission [{$permission}]."
        );

        return $this;
    }

    public function assertHasFeatureFlag(string $flag): static
    {
        Assert::assertTrue(
            $this->hasFeatureFlag($flag),
            "Expected user to have feature flag [{$flag}]."
        );

        return $this;
    }

    public function assertHasEntitlement(string $entitlement): static
    {
        Assert::assertTrue(
            $this->hasEntitlement($entitlement),
            "Expected user to have entitlement [{$entitlement}]."
        );

        return $this;
    }

    public function assertInOrganization(string $orgId): static
    {
        Assert::assertEquals(
            $orgId,
            $this->organizationId,
            "Expected organization [{$orgId}], got [{$this->organizationId}]."
        );

        return $this;
    }

    public function assertAudited(string $action, ?callable $callback = null): static
    {
        $matching = array_filter(
            $this->auditedEvents,
            fn ($e) => $e['action'] === $action
        );

        Assert::assertNotEmpty($matching, "Expected audit event [{$action}] was not logged.");

        if ($callback !== null) {
            foreach ($matching as $event) {
                if ($callback($event)) {
                    return $this;
                }
            }
            Assert::fail("Audit event [{$action}] logged but callback returned false.");
        }

        return $this;
    }

    public function assertNotAudited(string $action): static
    {
        $matching = array_filter(
            $this->auditedEvents,
            fn ($e) => $e['action'] === $action
        );

        Assert::assertEmpty($matching, "Unexpected audit event [{$action}] was logged.");

        return $this;
    }

    public function assertAuditedCount(int $count): static
    {
        Assert::assertCount($count, $this->auditedEvents);

        return $this;
    }

    /**
     * @return array<int, array{action: string, targets: array<int, mixed>, metadata: array<string, mixed>}>
     */
    public function getAuditedEvents(): array
    {
        return $this->auditedEvents;
    }

    public function clearAuditedEvents(): void
    {
        $this->auditedEvents = [];
    }

    private function buildSession(): WorkOSSession
    {
        $userId = 'fake_user_id';
        if ($this->user !== null && method_exists($this->user, 'getWorkOSId')) {
            $userId = $this->user->getWorkOSId() ?? 'fake_user_id';
        }

        return new WorkOSSession(
            userId: $userId,
            accessToken: 'fake_access_token',
            refreshToken: 'fake_refresh_token',
            expiresAt: Carbon::now()->addHour(),
            sessionId: 'fake_session_id',
            roles: $this->roles,
            permissions: $this->permissions,
            featureFlags: $this->featureFlags,
            entitlements: $this->entitlements,
            organizationId: $this->organizationId,
            impersonator: $this->impersonator,
        );
    }

    private function refreshSession(): void
    {
        if ($this->user !== null && method_exists($this->user, 'setWorkOSSession')) {
            $this->user->setWorkOSSession($this->buildSession());
        }
    }
}
