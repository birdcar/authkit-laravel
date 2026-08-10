<?php

declare(strict_types=1);

namespace Authkit\Authkit\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * GET /.well-known/oauth-protected-resource — RFC 9728 protected resource
 * metadata (the path is fixed by spec, not configurable). Field shape matches
 * WorkOS's AuthKit-for-MCP guide exactly (spec-phase-10 finding F2);
 * `scopes_supported` is emitted only when `authkit.mcp.scopes` is configured.
 */
final class OAuthProtectedResourceMetadataController
{
    public function __invoke(): JsonResponse
    {
        $domain = config('authkit.authkit_domain');
        $resource = config('authkit.mcp.resource_indicator');

        // Unconfigured: 404, not 500 — a default install that never touches
        // MCP must not expose a broken well-known endpoint. The deliberate
        // opposite of the authkit.mcp middleware's fail-fast exception for
        // the same missing config (failure mode F10).
        if (! is_string($domain) || trim($domain) === '' || ! is_string($resource) || trim($resource) === '') {
            abort(404);
        }

        $metadata = [
            'resource' => $resource,
            'authorization_servers' => ["https://{$domain}"],
            'bearer_methods_supported' => ['header'],
        ];

        $scopes = config('authkit.mcp.scopes');

        if (is_array($scopes) && $scopes !== []) {
            $metadata['scopes_supported'] = array_values(array_filter($scopes, 'is_string'));
        }

        return response()->json($metadata);
    }
}
