<?php

declare(strict_types=1);

namespace Authkit\Authkit\Listeners;

use Authkit\Authkit\Auth\AccessTokenClaims;
use Authkit\Authkit\Auth\JwtPayloadDecoder;
use Authkit\Authkit\Events\Login;
use Authkit\Authkit\Organizations\MembershipProjector;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Closes the gap between "WorkOS already knows about this org/membership"
 * (dashboard-created, directory-provisioned, invited, or created by another
 * app) and "the local projection has caught up" — synchronously, at login
 * time, before the response is returned, instead of waiting for the events
 * poller's next pass. The convergence itself lives in
 * {@see MembershipProjector}; this listener owns only the claims decode.
 */
final class UpsertOrganizationAndMembershipFromLogin
{
    public function __construct(private readonly MembershipProjector $projector) {}

    public function handle(Login $event): void
    {
        try {
            $this->sync($event);
        } catch (Throwable $e) {
            // Never let a projection-backfill failure break a successful login.
            Log::warning('authkit: login-time org/membership projection upsert failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function sync(Login $event): void
    {
        // Decodes the freshly-exchanged access token directly: it arrived
        // moments ago from authenticateWithCode()'s HTTPS response body, so the
        // transport is the trust boundary — there is no sealed cookie yet whose
        // signature-verification step could be reused. The DTO is shared with
        // the guard for shape, not because the same security invariant applies.
        $claims = AccessTokenClaims::fromPayload(
            JwtPayloadDecoder::decode($event->response->accessToken),
        );

        if ($claims->organizationId === null) {
            return; // no current org on this login — nothing to project
        }

        $this->projector->ensureProjected($claims->organizationId, $claims->sub);
    }
}
