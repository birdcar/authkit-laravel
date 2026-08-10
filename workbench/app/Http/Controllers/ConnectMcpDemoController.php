<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Authkit\Authkit\Connect\Data\ConnectApplication;
use Authkit\Authkit\Facades\Authkit;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Connect & MCP demo: lists the environment's Connect applications through
 * the registry facade, and points at the package's own RFC 9728 protected-
 * resource metadata document — the MCP side of the story (the authkit.mcp
 * bearer middleware itself is exercised by the workbench MCP server recipe
 * in routes/ai.php).
 */
final class ConnectMcpDemoController extends Controller
{
    public function applications(): JsonResponse
    {
        return response()->json([
            'applications' => Authkit::connect()->listApplications()
                ->map(fn (ConnectApplication $application): array => [
                    'id' => $application->id,
                    'client_id' => $application->clientId,
                    'name' => $application->name,
                    'type' => $application->applicationType,
                    'scopes' => $application->scopes,
                ])->values()->all(),
            'protected_resource_metadata_url' => route('authkit.oauth-protected-resource'),
        ]);
    }
}
