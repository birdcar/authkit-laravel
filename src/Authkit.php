<?php

declare(strict_types=1);

namespace Authkit\Authkit;

use Authkit\Authkit\Authorization\FgaChecker;
use Authkit\Authkit\Authorization\PermissionManager;
use Authkit\Authkit\Authorization\ResourceManager;
use Authkit\Authkit\Authorization\RoleManager;
use Authkit\Authkit\Connect\ConnectManager;
use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Enums\PortalIntent;
use Authkit\Authkit\Organizations\CurrentOrganizationResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use WorkOS\RequestOptions;

class Authkit
{
    /**
     * The app's local org model row for the current session's org_id claim,
     * or null when there is no current organization. Same source of truth as
     * $request->organization() — both delegate to one resolver instance.
     */
    public function currentOrganization(): ?Model
    {
        return app(CurrentOrganizationResolver::class)->resolve();
    }

    // Authorization accessors are container-resolved rather than
    // constructor-injected: this class is touched by several phases, and
    // additive app() resolution avoids cross-phase constructor collisions.

    public function roles(): RoleManager
    {
        return app(RoleManager::class);
    }

    public function permissions(): PermissionManager
    {
        return app(PermissionManager::class);
    }

    public function resources(): ResourceManager
    {
        return app(ResourceManager::class);
    }

    /**
     * The Connect OAuth/M2M application registry. Registry data and client
     * secrets are never persisted locally — WorkOS stays canonical.
     */
    public function connect(): ConnectManager
    {
        return app(ConnectManager::class);
    }

    /**
     * Mint an Admin Portal link for an organization's admin to visit —
     * server-side link mint only, no widget or JS token involvement (contract
     * decision: Widgets are excluded from v1 entirely). Accepts a raw WorkOS
     * organization id or any Eloquent model exposing a workos_id attribute
     * (i.e. a model using HasWorkosOrganization).
     *
     * @param  array<int, string>|null  $itContactEmails
     */
    public function portalLink(
        Model|string $organization,
        PortalIntent $intent,
        ?string $returnUrl = null,
        ?string $successUrl = null,
        ?array $itContactEmails = null,
    ): string {
        if (is_string($organization)) {
            $organizationId = $organization;
        } else {
            $workosId = $organization->getAttribute('workos_id');

            if (! is_string($workosId) || $workosId === '') {
                $key = $organization->getKey();

                throw new RuntimeException(sprintf(
                    'Cannot mint an Admin Portal link for [%s] #%s: its workos_id is empty. '
                    .'The organization has not synced to WorkOS yet (or the model carries no workos_id column).',
                    $organization::class,
                    is_scalar($key) ? (string) $key : '?',
                ));
            }

            $organizationId = $workosId;
        }

        $response = app(WorkosClientManager::class)->client()->adminPortal()->generateLink(
            organization: $organizationId,
            returnUrl: $returnUrl,
            successUrl: $successUrl,
            intent: $intent->toWorkos(),
            itContactEmails: $itContactEmails,
        );

        return $response->link;
    }

    /**
     * Explicit FGA check via the WorkOS Check API — one network call per
     * invocation, uncached by contract decision. When
     * $organizationMembershipId is omitted, it is resolved from the given (or
     * current) user and organization via the bound membership resolver, and
     * MembershipNotResolvedException is thrown when that fails — a projection
     * that hasn't synced is not a deny.
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
        return app(FgaChecker::class)->check(
            $permissionSlug,
            $resourceExternalId,
            $resourceTypeSlug,
            $organizationMembershipId,
            $user,
            $organizationId,
            $options,
        );
    }
}
