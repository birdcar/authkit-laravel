<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Authkit\Authkit\Enums\PortalIntent;
use Authkit\Authkit\Facades\Authkit;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Admin Portal demo: mints a portal link for the current organization. The
 * intent rides as a route parameter so all seven PortalIntent cases are
 * reachable through one route; an invalid intent throws ValueError loudly
 * rather than silently returning null.
 */
final class AdminPortalDemoController extends Controller
{
    public function link(string $intent): JsonResponse
    {
        $portalIntent = PortalIntent::from($intent);

        $organization = Authkit::currentOrganization();
        abort_unless($organization !== null, 422, 'No current organization on this session — log in with an organization context first.');

        return response()->json([
            'intent' => $portalIntent->value,
            'url' => Authkit::portalLink($organization, $portalIntent),
        ]);
    }
}
