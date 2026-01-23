<?php

declare(strict_types=1);

namespace WorkOS\AuthKit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use InvalidArgumentException;
use SensitiveParameter;
use WorkOS\AuthKit\Audit\AuditLogger;
use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\AuthKit\Auth\WorkOSSession;
use WorkOS\AuthKit\Testing\WorkOSFake;

class WorkOS
{
    /** @var WorkOSFake|null */
    private static $fake = null;

    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, class-string> */
    private const SERVICE_MAP = [
        'auditLogs' => \WorkOS\AuditLogs::class,
        'directorySync' => \WorkOS\DirectorySync::class,
        'mfa' => \WorkOS\MFA::class,
        'organizations' => \WorkOS\Organizations::class,
        'passwordless' => \WorkOS\Passwordless::class,
        'portal' => \WorkOS\Portal::class,
        'sso' => \WorkOS\SSO::class,
        'userManagement' => \WorkOS\UserManagement::class,
        'webhook' => \WorkOS\Webhook::class,
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
    public function loginUrl(?string $organizationId = null, ?array $state = null): string
    {
        /** @var \WorkOS\UserManagement $userManagement */
        $userManagement = $this->userManagement();

        return $userManagement->getAuthorizationUrl(
            redirectUri: config('workos.redirect_uri'),
            state: $state,
            provider: 'authkit',
            organizationId: $organizationId,
        );
    }

    public function logoutUrl(?string $returnTo = null): string
    {
        /** @var \WorkOS\UserManagement $userManagement */
        $userManagement = $this->userManagement();

        $sessionId = $this->session()?->sessionId;
        if ($sessionId === null) {
            throw new \RuntimeException('No active session to logout');
        }

        return $userManagement->getLogoutUrl($sessionId, $returnTo);
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

        return self::$fake;
    }

    /**
     * @param  array<string>  $roles
     * @param  array<string>  $permissions
     */
    public static function actingAs(
        Authenticatable $user,
        array $roles = [],
        array $permissions = [],
        ?string $organizationId = null,
    ): WorkOSFake {
        return static::fake()->actingAs($user, $roles, $permissions, $organizationId);
    }

    public static function isFaked(): bool
    {
        return self::$fake !== null;
    }

    public static function restore(): void
    {
        self::$fake = null;
        app()->forgetInstance('workos');
    }
}
