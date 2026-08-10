<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Authkit\Authkit\Facades\Authkit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workbench\App\Models\User;

/**
 * API Keys demo: issue and revoke on the user model via HasApiKeys. The raw
 * key value is echoed exactly once at creation — matching WorkOS's own
 * shown-once behavior — and is never retrievable again from any listing.
 * Validation of an issued key is exercised by the existing
 * /api-keys/whoami playground route (auth:authkit-key guard).
 */
final class ApiKeyDemoController extends Controller
{
    public function issue(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        // WorkOS scopes user keys to an organization membership, so the demo
        // reuses the session's current organization.
        $organization = Authkit::currentOrganization();
        abort_unless($organization !== null, 422, 'No current organization on this session — user API keys are organization-scoped.');

        $key = $user->createApiKey('workbench-demo', $organization);

        return response()->json([
            'id' => $key->id,
            'raw_value_shown_once' => $key->value,
        ]);
    }

    public function revoke(Request $request, string $apiKeyId): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $user->revokeApiKey($apiKeyId);

        return response()->json(['revoked' => $apiKeyId]);
    }
}
