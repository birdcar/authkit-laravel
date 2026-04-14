<?php

declare(strict_types=1);

namespace WorkOS\AuthKit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use InvalidArgumentException;
use SensitiveParameter;
use WorkOS\AuditLogs;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use WorkOS\AuthKit\Audit\AuditLogger;
use WorkOS\AuthKit\Auth\ApiKeyValidation;
use WorkOS\AuthKit\FGA\FGAService;
use WorkOS\AuthKit\FeatureFlags\FeatureFlagService;
use WorkOS\AuthKit\Services\DomainService;
use WorkOS\AuthKit\Services\PipesService;
use WorkOS\AuthKit\Services\RadarService;
use WorkOS\AuthKit\Services\VaultService;
use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\AuthKit\Auth\WorkOSSession;
use WorkOS\AuthKit\Testing\WorkOSFake;
use WorkOS\DirectorySync;
use WorkOS\MFA;
use WorkOS\Organizations;
use WorkOS\Passwordless;
use WorkOS\Portal;
use WorkOS\SSO;
use WorkOS\UserManagement;
use WorkOS\Webhook;

class WorkOS
{
    /** @var WorkOSFake|null */
    private static $fake = null;

    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, class-string> */
    private const array SERVICE_MAP = [
        'auditLogs' => AuditLogs::class,
        'directorySync' => DirectorySync::class,
        'mfa' => MFA::class,
        'organizations' => Organizations::class,
        'passwordless' => Passwordless::class,
        'portal' => Portal::class,
        'sso' => SSO::class,
        'userManagement' => UserManagement::class,
        'webhook' => Webhook::class,
    ];

    public function __construct(
        private readonly SessionManager $session,
    ) {}

    /**
     * @param  array<mixed>  $arguments
     */
    public function __call(string $name, array $arguments): object
    {
        if (! array_key_exists($name, self::SERVICE_MAP)) {
            throw new InvalidArgumentException(
                "WorkOS service [{$name}] is not supported. Available services: ".implode(', ', array_keys(self::SERVICE_MAP))
            );
        }

        return $this->instances[$name] ??= new (self::SERVICE_MAP[$name]);
    }

    public function userManagement(): UserManagement
    {
        /** @var UserManagement */
        return $this->instances['userManagement'] ??= new UserManagement;
    }

    public function organizations(): Organizations
    {
        /** @var Organizations */
        return $this->instances['organizations'] ??= new Organizations;
    }

    public function sso(): SSO
    {
        /** @var SSO */
        return $this->instances['sso'] ??= new SSO;
    }

    public function directorySync(): DirectorySync
    {
        /** @var DirectorySync */
        return $this->instances['directorySync'] ??= new DirectorySync;
    }

    public function auditLogs(): AuditLogs
    {
        /** @var AuditLogs */
        return $this->instances['auditLogs'] ??= new AuditLogs;
    }

    public function webhook(): Webhook
    {
        /** @var Webhook */
        return $this->instances['webhook'] ??= new Webhook;
    }

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
        /** @var UserManagement $userManagement */
        $userManagement = $this->userManagement();

        return $userManagement->getAuthorizationUrl(
            redirectUri: config('workos.redirect_uri'),
            state: $state,
            provider: 'authkit',
            organizationId: $organizationId,
            loginHint: $loginHint,
            screenHint: $screenHint,
        );
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

    public function flags(): FeatureFlagService
    {
        /** @var FeatureFlagService */
        return $this->instances['flags'] ??= new FeatureFlagService(
            $this->session,
            $this->organizations(),
        );
    }

    public function fga(): FGAService
    {
        /** @var FGAService */
        return $this->instances['fga'] ??= app(FGAService::class);
    }

    public function vault(): VaultService
    {
        if (! config('workos.features.vault', false)) {
            throw new \RuntimeException('WorkOS Vault is not enabled. Set WORKOS_FEATURE_VAULT=true in your .env.');
        }

        /** @var VaultService */
        return $this->instances['vault'] ??= new VaultService;
    }

    public function radar(): RadarService
    {
        if (! config('workos.features.radar', false)) {
            throw new \RuntimeException('WorkOS Radar is not enabled. Set WORKOS_FEATURE_RADAR=true in your .env.');
        }

        /** @var RadarService */
        return $this->instances['radar'] ??= new RadarService;
    }

    public function pipes(): PipesService
    {
        if (! config('workos.features.pipes', false)) {
            throw new \RuntimeException('WorkOS Pipes is not enabled. Set WORKOS_FEATURE_PIPES=true in your .env.');
        }

        /** @var PipesService */
        return $this->instances['pipes'] ??= new PipesService;
    }

    public function domains(): DomainService
    {
        if (! config('workos.features.domain_verification', false)) {
            throw new \RuntimeException('WorkOS Domain Verification is not enabled. Set WORKOS_FEATURE_DOMAIN_VERIFICATION=true in your .env.');
        }

        /** @var DomainService */
        return $this->instances['domains'] ??= new DomainService;
    }

    public function validateApiKey(string $key): ?ApiKeyValidation
    {
        try {
            $client = new Client;
            $baseUrl = config('workos.api_keys.base_url', 'https://api.workos.com');

            $response = $client->post("{$baseUrl}/api_keys/validations", [
                'headers' => [
                    'Authorization' => 'Bearer '.\WorkOS\WorkOS::getApiKey(),
                    'Content-Type' => 'application/json',
                ],
                'json' => ['value' => $key],
            ]);

            /** @var array<string, mixed> $body */
            $body = json_decode((string) $response->getBody(), true);

            if (! isset($body['api_key']) || ! is_array($body['api_key'])) {
                return null;
            }

            return ApiKeyValidation::fromResponse($body['api_key']);
        } catch (RequestException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $authResponse
     */
    public function storeSession(#[SensitiveParameter] array $authResponse): WorkOSSession
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
