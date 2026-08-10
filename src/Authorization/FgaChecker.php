<?php

declare(strict_types=1);

namespace Authkit\Authkit\Authorization;

use Authkit\Authkit\Contracts\HasAccessTokenClaims;
use Authkit\Authkit\Contracts\ResolvesOrganizationMembershipId;
use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Exceptions\MembershipNotResolvedException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use WorkOS\RequestOptions;

/**
 * Fine-grained authorization via the WorkOS Check API — the explicit
 * escalation path beyond claims-shaped RBAC. Every call hits the network by
 * contract decision: a stale cache entry is a stale permission decision, so
 * there is no cache and no request-level memoization here. Do not add one.
 *
 * WorkOS-down behavior is deliberately fail-loud: after the SDK's own retries
 * exhaust, WorkOSException propagates uncaught rather than being masked as a
 * deny (spec-phase-5 Failure Mode 11).
 */
final class FgaChecker
{
    public function __construct(
        private readonly WorkosClientManager $clients,
        private readonly ResolvesOrganizationMembershipId $membershipResolver,
    ) {}

    /**
     * The ?RequestOptions parameter exists for SDK-signature parity and
     * per-call timeout/retry overrides, not idempotency — check() is a read.
     */
    public function check(
        string $permissionSlug,
        string $resourceExternalId,
        string $resourceTypeSlug,
        ?string $organizationMembershipId = null,
        ?Authenticatable $user = null,
        ?string $organizationId = null,
        ?RequestOptions $options = null,
    ): bool {
        $membershipId = $organizationMembershipId ?? $this->resolveMembershipId($user, $organizationId);

        $result = $this->clients->client()->authorization()->check(
            $membershipId,
            $permissionSlug,
            ResourceTarget::byExternalId($resourceExternalId, $resourceTypeSlug)->toSdkTarget(),
            $options,
        );

        return $result->authorized;
    }

    private function resolveMembershipId(?Authenticatable $user, ?string $organizationId): string
    {
        $user ??= Auth::guard('workos')->user();
        $organizationId ??= $this->currentOrganizationIdFromClaims();

        $membershipId = $user !== null && $organizationId !== null
            ? $this->membershipResolver->resolve($user, $organizationId)
            : null;

        if ($membershipId === null) {
            throw MembershipNotResolvedException::forContext(
                $this->authIdentifier($user),
                $organizationId ?? 'unknown',
            );
        }

        return $membershipId;
    }

    private function authIdentifier(?Authenticatable $user): int|string
    {
        $identifier = $user?->getAuthIdentifier();

        return is_int($identifier) || is_string($identifier) ? $identifier : 'guest';
    }

    private function currentOrganizationIdFromClaims(): ?string
    {
        $guard = Auth::guard('workos');

        if (! $guard instanceof HasAccessTokenClaims) {
            return null;
        }

        $organizationId = $guard->accessTokenClaims()['org_id'] ?? null;

        return is_string($organizationId) && $organizationId !== '' ? $organizationId : null;
    }
}
