<?php

declare(strict_types=1);

namespace Authkit\Authkit\Testing\Fakes;

use Authkit\Authkit\Models\WorkosMembership;
use Authkit\Authkit\Organizations\MembershipManager;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Assert;
use WorkOS\PaginatedResponse;
use WorkOS\Resource\OrganizationMembership;
use WorkOS\Resource\UserOrganizationMembership;

/**
 * An in-memory {@see MembershipManager}: a stateful registry instead of the
 * WorkOS API. Crucially it keeps the REAL manager's local contract — every
 * mutation upserts the workos_memberships projection — so application code
 * that creates a membership and then reads it back through an org model's
 * memberships() relation or a user's organizations() relation behaves
 * exactly as it would in production.
 */
final class MembershipsFake extends MembershipManager
{
    /** @var array<string, array<string, mixed>> raw resource attributes keyed by membership id */
    private array $memberships = [];

    /** @var list<OrganizationMembership> memberships created via create(), oldest first */
    private array $created = [];

    /** @var array<string, string|list<string>> membership id => role passed to update() */
    private array $updated = [];

    /** @var list<string> membership ids passed to delete() */
    private array $deleted = [];

    private int $sequence = 0;

    public function __construct()
    {
        // Deliberately no parent call: the fake never touches the WorkOS
        // client, and every inherited method that would is overridden below.
    }

    /**
     * Put a membership into the registry (and the projection) without
     * recording a create — fixture state for tests exercising reads,
     * updates, and removals.
     *
     * @param  array<string, mixed>  $attributes  raw resource attributes, snake_case as the API returns them
     */
    public function seed(Model|string $organization, Model|Authenticatable|string $user, string $role = 'member', array $attributes = []): UserOrganizationMembership
    {
        $record = $this->makeRecord($this->resolveOrganizationId($organization), $this->resolveUserId($user), $role, $attributes);

        $this->memberships[$this->recordId($record)] = $record;
        $this->upsertProjectionFromRecord($record);

        return UserOrganizationMembership::fromArray($record);
    }

    /**
     * @param  string|list<string>|null  $role
     */
    public function create(Model|string $organization, Model|Authenticatable|string $user, string|array|null $role = null): OrganizationMembership
    {
        // WorkOS applies the environment's default role when none is given;
        // the fake models that with 'member', the dashboard default.
        $slug = is_array($role) ? ($role[0] ?? 'member') : ($role ?? 'member');

        $record = $this->makeRecord(
            $this->resolveOrganizationId($organization),
            $this->resolveUserId($user),
            $slug,
            is_array($role) ? ['roles' => array_map(static fn (string $each): array => ['slug' => $each], $role)] : [],
        );

        $this->memberships[$this->recordId($record)] = $record;
        $this->upsertProjectionFromRecord($record);

        $membership = OrganizationMembership::fromArray($record);
        $this->created[] = $membership;

        return $membership;
    }

    public function get(string $membershipId): UserOrganizationMembership
    {
        return UserOrganizationMembership::fromArray($this->record($membershipId));
    }

    /**
     * @param  list<string>|null  $statuses
     */
    public function list(
        Model|string|null $organization = null,
        Model|Authenticatable|string|null $user = null,
        ?array $statuses = null,
        ?string $before = null,
        ?string $after = null,
        ?int $limit = null,
    ): PaginatedResponse {
        $organizationId = $organization !== null ? $this->resolveOrganizationId($organization) : null;
        $userId = $user !== null ? $this->resolveUserId($user) : null;

        // WorkOS returns only active memberships unless statuses says otherwise.
        $statuses ??= ['active'];

        $matches = array_values(array_map(
            static fn (array $record): UserOrganizationMembership => UserOrganizationMembership::fromArray($record),
            array_filter(
                $this->memberships,
                static fn (array $record): bool => ($organizationId === null || $record['organization_id'] === $organizationId)
                    && ($userId === null || $record['user_id'] === $userId)
                    && in_array($record['status'], $statuses, true),
            ),
        ));

        return new PaginatedResponse($matches, []);
    }

    /**
     * @param  string|list<string>  $role
     */
    public function update(string $membershipId, string|array $role): UserOrganizationMembership
    {
        $record = $this->record($membershipId);

        $slug = is_array($role) ? ($role[0] ?? 'member') : $role;

        $record['role'] = ['slug' => $slug];
        $record['roles'] = is_array($role)
            ? array_map(static fn (string $each): array => ['slug' => $each], $role)
            : [['slug' => $slug]];
        $record['updated_at'] = (new DateTimeImmutable)->format(DateTimeInterface::RFC3339_EXTENDED);

        $this->memberships[$membershipId] = $record;
        $this->upsertProjectionFromRecord($record);
        $this->updated[$membershipId] = $role;

        return UserOrganizationMembership::fromArray($record);
    }

    public function delete(string $membershipId): void
    {
        // Same missing-id contract as the real API: deleting what does not
        // exist is an error, not a silent no-op.
        $this->record($membershipId);

        unset($this->memberships[$membershipId]);
        WorkosMembership::query()->where('workos_id', $membershipId)->delete();

        $this->deleted[] = $membershipId;
    }

    public function deactivate(string $membershipId): OrganizationMembership
    {
        return OrganizationMembership::fromArray($this->transition($membershipId, 'inactive'));
    }

    public function reactivate(string $membershipId): UserOrganizationMembership
    {
        return UserOrganizationMembership::fromArray($this->transition($membershipId, 'active'));
    }

    /**
     * @param  (callable(OrganizationMembership): bool)|null  $callback
     */
    public function assertCreated(Model|string $organization, Model|Authenticatable|string $user, ?callable $callback = null): void
    {
        $organizationId = $this->resolveOrganizationId($organization);
        $userId = $this->resolveUserId($user);

        $matches = array_filter(
            $this->created,
            static fn (OrganizationMembership $membership): bool => $membership->organizationId === $organizationId
                && $membership->userId === $userId
                && ($callback === null || $callback($membership)),
        );

        Assert::assertNotEmpty($matches, sprintf(
            'Expected a membership created for user [%s] in organization [%s]%s but none matched.',
            $userId,
            $organizationId,
            $callback !== null ? ' passing the given callback' : '',
        ));
    }

    public function assertNothingCreated(): void
    {
        Assert::assertEmpty($this->created, sprintf(
            'Expected no memberships to be created, but %d %s.',
            count($this->created),
            count($this->created) === 1 ? 'was' : 'were',
        ));
    }

    /**
     * @param  string|list<string>|null  $role
     */
    public function assertUpdated(string $membershipId, string|array|null $role = null): void
    {
        Assert::assertArrayHasKey($membershipId, $this->updated, "Expected membership [{$membershipId}] to be updated, but it was not.");

        if ($role !== null) {
            Assert::assertSame($role, $this->updated[$membershipId], sprintf(
                'Membership [%s] was updated, but not to the expected role.',
                $membershipId,
            ));
        }
    }

    public function assertDeleted(string $membershipId): void
    {
        Assert::assertContains($membershipId, $this->deleted, "Expected membership [{$membershipId}] to be deleted, but it was not.");
    }

    public function assertNotDeleted(string $membershipId): void
    {
        Assert::assertNotContains($membershipId, $this->deleted, "Expected membership [{$membershipId}] not to be deleted, but it was.");
    }

    /**
     * @return array<string, mixed>
     */
    private function record(string $membershipId): array
    {
        return $this->memberships[$membershipId] ?? throw new InvalidArgumentException(
            "No fake membership [{$membershipId}] exists. Create one first with create() or seed().",
        );
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function recordId(array $record): string
    {
        $id = $record['id'] ?? null;

        if (! is_string($id)) {
            throw new LogicException('Fake membership records always carry a string id — this record was built outside makeRecord().');
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function transition(string $membershipId, string $status): array
    {
        $record = $this->record($membershipId);

        $record['status'] = $status;
        $record['updated_at'] = (new DateTimeImmutable)->format(DateTimeInterface::RFC3339_EXTENDED);

        $this->memberships[$membershipId] = $record;
        $this->upsertProjectionFromRecord($record);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function makeRecord(string $organizationId, string $userId, string $role, array $attributes = []): array
    {
        $id = 'om_fake_'.++$this->sequence;
        $now = (new DateTimeImmutable)->format(DateTimeInterface::RFC3339_EXTENDED);

        return array_merge([
            'object' => 'organization_membership',
            'id' => $id,
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'status' => 'active',
            'directory_managed' => false,
            'created_at' => $now,
            'updated_at' => $now,
            'role' => ['slug' => $role],
            'roles' => [['slug' => $role]],
            'user' => [
                'id' => $userId,
                'email' => "{$userId}@fake.workos.test",
                'email_verified' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], $attributes);
    }

    /**
     * The same synchronous projection contract as the real manager — what
     * makes relation reads work in tests.
     *
     * @param  array<string, mixed>  $record
     */
    private function upsertProjectionFromRecord(array $record): void
    {
        $role = $record['role'];

        WorkosMembership::query()->updateOrCreate(
            ['workos_id' => $record['id']],
            [
                'organization_id' => $record['organization_id'],
                'user_id' => $record['user_id'],
                'role' => is_array($role) ? $role['slug'] : null,
                'status' => $record['status'],
            ],
        );
    }

    private function resolveOrganizationId(Model|string $organization): string
    {
        if (is_string($organization)) {
            return $organization;
        }

        $workosId = $organization->getAttribute('workos_id');

        if (! is_string($workosId) || $workosId === '') {
            throw new InvalidArgumentException(sprintf(
                'The fake cannot read an organization id from [%s]: its workos_id is empty. Sync it '
                .'first (or set one directly in the test).',
                $organization::class,
            ));
        }

        return $workosId;
    }

    private function resolveUserId(Model|Authenticatable|string $user): string
    {
        if (is_string($user)) {
            return $user;
        }

        $workosId = $user instanceof Model ? $user->getAttribute('workos_id') : null;

        if (! is_string($workosId) || $workosId === '') {
            throw new InvalidArgumentException(sprintf(
                'The fake cannot read a WorkOS user id from [%s]: it has no workos_id. Set one '
                ."(User::factory()->create(['workos_id' => 'user_123'])) or pass the id directly.",
                get_debug_type($user),
            ));
        }

        return $workosId;
    }
}
