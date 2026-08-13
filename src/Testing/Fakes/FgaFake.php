<?php

declare(strict_types=1);

namespace Authkit\Authkit\Testing\Fakes;

use Authkit\Authkit\Authorization\FgaChecker;
use Authkit\Authkit\Authorization\ResourceTarget;
use Authkit\Authkit\Contracts\HasAccessTokenClaims;
use Authkit\Authkit\Contracts\WorkosResource;
use Authkit\Authkit\Exceptions\MembershipNotResolvedException;
use Authkit\Authkit\Policies\WorkosResourcePolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use WorkOS\PaginatedResponse;
use WorkOS\RequestOptions;
use WorkOS\Resource\AuthorizationAssignment;

/**
 * An in-memory {@see FgaChecker}: scripted decisions instead of the Check
 * API, recorded calls instead of network traffic.
 *
 * The default is DENY — the same posture as {@see WorkosResourcePolicy}
 * — so a test that forgets to script a decision fails loudly on the
 * permission it forgot, never silently passes.
 *
 * Unlike the real checker, an omitted membership id does not consult the
 * membership projection (a table most consumer tests never seed). When the
 * acting session carries a user and an organization, a synthetic
 * `om_fake_...` id is recorded instead; without that context this throws
 * {@see MembershipNotResolvedException} exactly like production.
 */
final class FgaFake extends FgaChecker
{
    /** @var array<string, bool> */
    private array $decisions = [];

    /** @var list<array{permission: string, resource: string, membership_id: string}> */
    private array $checks = [];

    /** @var list<mixed> */
    private array $scriptedResources = [];

    /** @var list<mixed> */
    private array $scriptedMemberships = [];

    public function __construct()
    {
        // Deliberately no parent call: the fake never touches the WorkOS
        // client or the membership resolver, and every inherited method that
        // would is overridden below.
    }

    /**
     * Script an ALLOW for a permission on a resource. Pass a
     * {@see WorkosResource} model, or a raw external id plus its type slug.
     */
    public function allow(string $permissionSlug, WorkosResource|string $resource, ?string $resourceTypeSlug = null): self
    {
        $this->decisions[$this->decisionKey($permissionSlug, $resource, $resourceTypeSlug)] = true;

        return $this;
    }

    /**
     * Script an explicit DENY — behaviorally the default, but a scripted deny
     * documents intent and wins over an earlier allow for the same pair.
     */
    public function deny(string $permissionSlug, WorkosResource|string $resource, ?string $resourceTypeSlug = null): self
    {
        $this->decisions[$this->decisionKey($permissionSlug, $resource, $resourceTypeSlug)] = false;

        return $this;
    }

    public function check(
        string $permissionSlug,
        string $resourceExternalId,
        string $resourceTypeSlug,
        ?string $organizationMembershipId = null,
        ?Authenticatable $user = null,
        ?string $organizationId = null,
        ?RequestOptions $options = null,
    ): bool {
        $membershipId = $organizationMembershipId ?? $this->fakeMembershipId($user, $organizationId);
        $resource = ResourceTarget::byExternalId($resourceExternalId, $resourceTypeSlug)->cacheFragment();

        $this->checks[] = [
            'permission' => $permissionSlug,
            'resource' => $resource,
            'membership_id' => $membershipId,
        ];

        return $this->decisions[$permissionSlug.'|'.$resource] ?? false;
    }

    /**
     * No cache exists here, so invalidation is a no-op — kept so production
     * write paths calling it unconditionally run unchanged under the fake.
     */
    public function forgetCache(): void {}

    /**
     * Fixture items served by {@see listResourcesForMembership()}.
     *
     * @param  list<mixed>  $items
     */
    public function scriptResourcesForMembership(array $items): self
    {
        $this->scriptedResources = $items;

        return $this;
    }

    /**
     * Fixture items served by both membership-listing methods.
     *
     * @param  list<mixed>  $items
     */
    public function scriptMembershipsForResource(array $items): self
    {
        $this->scriptedMemberships = $items;

        return $this;
    }

    public function listResourcesForMembership(
        string $organizationMembershipId,
        ResourceTarget $parentResource,
        string $permissionSlug,
        ?string $before = null,
        ?string $after = null,
        ?int $limit = null,
    ): PaginatedResponse {
        return new PaginatedResponse($this->scriptedResources, []);
    }

    public function listMembershipsForResource(
        string $resourceId,
        string $permissionSlug,
        ?string $before = null,
        ?string $after = null,
        ?int $limit = null,
        ?AuthorizationAssignment $assignment = null,
    ): PaginatedResponse {
        return new PaginatedResponse($this->scriptedMemberships, []);
    }

    public function listMembershipsForResourceByExternalId(
        string $organizationId,
        string $resourceTypeSlug,
        string $externalId,
        string $permissionSlug,
        ?string $before = null,
        ?string $after = null,
        ?int $limit = null,
        ?AuthorizationAssignment $assignment = null,
    ): PaginatedResponse {
        return new PaginatedResponse($this->scriptedMemberships, []);
    }

    public function assertChecked(string $permissionSlug, WorkosResource|string|null $resource = null, ?string $resourceTypeSlug = null): void
    {
        Assert::assertNotEmpty(
            $this->matching($permissionSlug, $resource, $resourceTypeSlug),
            sprintf(
                'Expected an FGA check for %s but none was performed. %s',
                $this->describeExpectation($permissionSlug, $resource, $resourceTypeSlug),
                $this->describePerformedChecks(),
            ),
        );
    }

    public function assertNotChecked(string $permissionSlug, WorkosResource|string|null $resource = null, ?string $resourceTypeSlug = null): void
    {
        Assert::assertEmpty(
            $this->matching($permissionSlug, $resource, $resourceTypeSlug),
            sprintf(
                'Unexpected FGA check for %s. %s',
                $this->describeExpectation($permissionSlug, $resource, $resourceTypeSlug),
                $this->describePerformedChecks(),
            ),
        );
    }

    public function assertNothingChecked(): void
    {
        Assert::assertEmpty(
            $this->checks,
            sprintf('Expected no FGA checks at all. %s', $this->describePerformedChecks()),
        );
    }

    /**
     * Every recorded check, oldest first — each entry carries `permission`,
     * `resource` (a `ext:type:id` fragment) and `membership_id`.
     *
     * @return list<array{permission: string, resource: string, membership_id: string}>
     */
    public function recordedChecks(): array
    {
        return $this->checks;
    }

    /**
     * @return list<array{permission: string, resource: string, membership_id: string}>
     */
    private function matching(string $permissionSlug, WorkosResource|string|null $resource, ?string $resourceTypeSlug): array
    {
        $fragment = $resource === null ? null : $this->fragmentFor($resource, $resourceTypeSlug);

        return array_values(array_filter(
            $this->checks,
            static fn (array $check): bool => $check['permission'] === $permissionSlug
                && ($fragment === null || $check['resource'] === $fragment),
        ));
    }

    private function decisionKey(string $permissionSlug, WorkosResource|string $resource, ?string $resourceTypeSlug): string
    {
        return $permissionSlug.'|'.$this->fragmentFor($resource, $resourceTypeSlug);
    }

    private function fragmentFor(WorkosResource|string $resource, ?string $resourceTypeSlug): string
    {
        if ($resource instanceof WorkosResource) {
            return ResourceTarget::byExternalId(
                $resource->workosResourceExternalId(),
                $resource->workosResourceType(),
            )->cacheFragment();
        }

        if ($resourceTypeSlug === null || $resourceTypeSlug === '') {
            throw new InvalidArgumentException(
                "A raw external id needs its resource type slug too: allow('{$resource}', ..., 'project'). "
                .'Pass a model using HasWorkosResource to have both derived for you.',
            );
        }

        return ResourceTarget::byExternalId($resource, $resourceTypeSlug)->cacheFragment();
    }

    /**
     * Mirrors the real checker's contract — no membership context is still an
     * exception — without consulting the membership projection table.
     */
    private function fakeMembershipId(?Authenticatable $user, ?string $organizationId): string
    {
        $user ??= Auth::guard('workos')->user();
        $organizationId ??= $this->organizationIdFromClaims();

        if ($user === null || $organizationId === null) {
            $identifier = $user?->getAuthIdentifier();

            throw MembershipNotResolvedException::forContext(
                is_int($identifier) || is_string($identifier) ? $identifier : 'guest',
                $organizationId ?? 'unknown',
            );
        }

        $identifier = $user->getAuthIdentifier();

        return sprintf(
            'om_fake_%s_%s',
            is_scalar($identifier) ? (string) $identifier : 'user',
            $organizationId,
        );
    }

    private function organizationIdFromClaims(): ?string
    {
        $guard = Auth::guard('workos');

        if (! $guard instanceof HasAccessTokenClaims) {
            return null;
        }

        $organizationId = $guard->accessTokenClaims()['org_id'] ?? null;

        return is_string($organizationId) && $organizationId !== '' ? $organizationId : null;
    }

    private function describeExpectation(string $permissionSlug, WorkosResource|string|null $resource, ?string $resourceTypeSlug): string
    {
        return $resource === null
            ? "[{$permissionSlug}]"
            : "[{$permissionSlug}] on [{$this->fragmentFor($resource, $resourceTypeSlug)}]";
    }

    private function describePerformedChecks(): string
    {
        if ($this->checks === []) {
            return 'No checks were performed.';
        }

        $lines = array_map(
            static fn (array $check): string => "[{$check['permission']}] on [{$check['resource']}] as [{$check['membership_id']}]",
            $this->checks,
        );

        return 'Performed checks: '.implode(', ', $lines).'.';
    }
}
