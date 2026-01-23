<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Testing;

use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use PHPUnit\Framework\Assert;
use WorkOS\AuthKit\Auth\WorkOSSession;

class WorkOSFake
{
    private ?Authenticatable $user = null;

    /** @var array<string> */
    private array $roles = [];

    /** @var array<string> */
    private array $permissions = [];

    private ?string $organizationId = null;

    /** @var array<string, mixed>|null */
    private ?array $impersonator = null;

    /** @var array<int, array{action: string, targets: array<int, mixed>, metadata: array<string, mixed>}> */
    private array $auditedEvents = [];

    /**
     * @param  array<string>  $roles
     * @param  array<string>  $permissions
     */
    public function actingAs(
        Authenticatable $user,
        array $roles = [],
        array $permissions = [],
        ?string $organizationId = null,
    ): static {
        $this->user = $user;
        $this->roles = $roles;
        $this->permissions = $permissions;
        $this->organizationId = $organizationId;

        $session = $this->buildSession();

        if (method_exists($user, 'setWorkOSSession')) {
            $user->setWorkOSSession($session);
        }

        /** @var StatefulGuard $guard */
        $guard = auth(config('workos.guard', 'workos'));
        $guard->login($user);

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
     * @param  array<int, mixed>  $targets
     * @param  array<string, mixed>  $metadata
     */
    public function audit(string $action, array $targets = [], array $metadata = []): void
    {
        $this->auditedEvents[] = compact('action', 'targets', 'metadata');
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
