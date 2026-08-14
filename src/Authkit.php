<?php

declare(strict_types=1);

namespace Authkit\Authkit;

use Authkit\Authkit\Authorization\FgaChecker;
use Authkit\Authkit\Authorization\PermissionManager;
use Authkit\Authkit\Authorization\ResourceManager;
use Authkit\Authkit\Authorization\RoleManager;
use Authkit\Authkit\Connect\ConnectManager;
use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\CorsOrigins\CorsOriginManager;
use Authkit\Authkit\Enums\PortalIntent;
use Authkit\Authkit\Groups\GroupManager;
use Authkit\Authkit\Invitations\InvitationManager;
use Authkit\Authkit\JwtTemplates\JwtTemplateManager;
use Authkit\Authkit\Organizations\CurrentOrganizationResolver;
use Authkit\Authkit\Organizations\MembershipManager;
use Authkit\Authkit\Organizations\OrganizationSwitcher;
use Authkit\Authkit\Organizations\OrganizationSwitchResult;
use Authkit\Authkit\Pipes\PipesManager;
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
     * The FGA surface behind check(): resource-graph discovery helpers
     * (listResourcesForMembership, listMembershipsForResource[ByExternalId])
     * and the opt-in check cache's forgetCache().
     */
    public function fga(): FgaChecker
    {
        return app(FgaChecker::class);
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
     * Invitations management: send/list/get/resend/revoke/accept plus the
     * accept-URL helper. Pure passthroughs — WorkOS stays canonical.
     */
    public function invitations(): InvitationManager
    {
        return app(InvitationManager::class);
    }

    /**
     * Organization-membership management: create/get/list/update/delete plus
     * deactivate/reactivate. WorkOS stays canonical, and every mutation also
     * upserts the local workos_memberships projection synchronously so the
     * request that made the change reads its own write.
     */
    public function memberships(): MembershipManager
    {
        return app(MembershipManager::class);
    }

    /**
     * Refresh the current request's sealed session scoped to the given
     * organization — the server-side counterpart of POSTing the
     * `authkit.switch-org` route. True means the new cookie is queued and
     * the switch takes effect on the next request (so redirect after it);
     * false means there was no session to refresh or WorkOS refused (no
     * active membership, rotated refresh token), in which case callers fall
     * back to a re-authorize redirect or their own error state.
     */
    public function switchToOrganization(Model|string $organization): bool
    {
        return app(OrganizationSwitcher::class)->switch($organization) === OrganizationSwitchResult::Switched;
    }

    /**
     * Environment JWT template get/update. update() is deliberately loud —
     * it logs a warning and dispatches JwtTemplateUpdated on every write,
     * because template edits change what rides in the 4KB-bounded sealed
     * session cookie.
     */
    public function jwtTemplate(): JwtTemplateManager
    {
        return app(JwtTemplateManager::class);
    }

    /**
     * CORS origin management: list and create only — the SDK exposes no
     * delete endpoint.
     */
    public function corsOrigins(): CorsOriginManager
    {
        return app(CorsOriginManager::class);
    }

    /**
     * Organization groups: CRUD, membership, and group role assignments
     * (whose mutations bust the FGA check cache).
     */
    public function groups(): GroupManager
    {
        return app(GroupManager::class);
    }

    /**
     * Pipes connected accounts: live read-throughs for a user's connected
     * providers and auto-refreshed access tokens, plus the org-level
     * provider-config passthrough. No local projection exists by contract
     * decision — every call is an uncached WorkOS read, so there is no
     * cache to go stale and no disconnect/accessToken race to lose.
     */
    public function pipes(): PipesManager
    {
        return app(PipesManager::class);
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
