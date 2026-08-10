<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Authkit\Authkit\Facades\Authkit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workbench\App\Models\User;

/**
 * RBAC and FGA in one controller because both answer "can this user do
 * this" at two different granularities: rbac() reads the JWT permission
 * claims with zero HTTP (ClaimsGateHook behind $user->can()), fga() is the
 * explicit escalation path through the WorkOS Check API.
 */
final class AuthorizationDemoController extends Controller
{
    public function rbac(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json([
            // True when the session's JWT permissions claim carries the slug.
            'can_manage_posts' => $user->can('posts.manage'),
            // Always false: the claims hook returns true-or-null (never a
            // global deny), and no Gate ability by this name exists — the
            // legible negative case for the acceptance suite.
            'can_unknown_permission' => $user->can('nonexistent.permission'),
        ]);
    }

    public function fga(Request $request, string $resourceExternalId): JsonResponse
    {
        // No fga()/ResourceTarget wrapper at the call site — Authkit::check()
        // is a top-level method and resolves the organization membership id
        // itself from the authenticated guard user + current-org claim.
        $authorized = Authkit::check(
            permissionSlug: 'posts.manage',
            resourceExternalId: $resourceExternalId,
            resourceTypeSlug: 'document',
        );

        return response()->json(['authorized' => $authorized]);
    }
}
