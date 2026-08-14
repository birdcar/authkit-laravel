<?php

declare(strict_types=1);

namespace Authkit\Authkit\Organizations;

use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Http\SessionCookie;
use Authkit\Authkit\Support\AuthkitConfig;
use Authkit\Authkit\Testing\Fakes\OrganizationSwitchFake;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cookie;
use InvalidArgumentException;

/**
 * Server-side organization switch: refreshes the current request's sealed
 * session scoped to the target organization — the same SDK refresh the
 * `authkit.switch-org` route performs, extracted so non-route flows
 * (onboarding after creating the first team, an org-less-session handoff)
 * can switch without a browser round-trip through the POST route.
 *
 * On success the new session cookie is QUEUED (attached to the response by
 * the web group's AddQueuedCookiesToResponse), so any caller that owns any
 * response — controller, Livewire action, middleware — inherits it. The
 * refreshed claims take effect on the NEXT request: the current request's
 * access token is already unsealed, so callers redirect after switching.
 * The resolver memo is cleared here anyway, which keeps the rule simple:
 * after a switch, nothing serves stale organization state.
 *
 * Deliberately bypasses the SessionRefresher single-flight lock — an
 * explicit switch is a single request with nothing to coordinate against
 * (see SwitchOrganizationController, which shares this reasoning).
 *
 * Not final: {@see OrganizationSwitchFake}
 * extends it.
 */
class OrganizationSwitcher
{
    public function __construct(private readonly WorkosClientManager $clients) {}

    public function switch(Model|string $organization): OrganizationSwitchResult
    {
        $organizationId = $this->organizationId($organization);

        $sealed = request()->cookie(SessionCookie::name());

        if (! is_string($sealed) || $sealed === '') {
            return OrganizationSwitchResult::NoSession;
        }

        $result = $this->clients->client()->sessionManager()->refresh(
            sessionData: $sealed,
            cookiePassword: AuthkitConfig::cookiePassword(),
            clientId: AuthkitConfig::clientId(),
            organizationId: $organizationId,
        );

        $sealedSession = $result['sealed_session'] ?? null;

        if (($result['authenticated'] ?? false) !== true || ! is_string($sealedSession) || $sealedSession === '') {
            return OrganizationSwitchResult::Refused;
        }

        Cookie::queue(SessionCookie::issue($sealedSession));

        app(CurrentOrganizationResolver::class)->forget();

        return OrganizationSwitchResult::Switched;
    }

    private function organizationId(Model|string $organization): string
    {
        if (is_string($organization)) {
            if ($organization === '') {
                throw new InvalidArgumentException(
                    'An empty string is not a WorkOS organization id. Pass an org_... id or a synced organization model.',
                );
            }

            return $organization;
        }

        $workosId = $organization->getAttribute('workos_id');

        if (! is_string($workosId) || $workosId === '') {
            throw new InvalidArgumentException(sprintf(
                'Cannot switch to [%s] #%s: its workos_id is empty — the organization has not synced '
                .'to WorkOS yet.',
                $organization::class,
                is_scalar($key = $organization->getKey()) ? (string) $key : '?',
            ));
        }

        return $workosId;
    }
}
