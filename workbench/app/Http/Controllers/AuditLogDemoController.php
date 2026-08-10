<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Authkit\Authkit\Facades\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Workbench\App\Models\Post;

/**
 * Audit Logs demo: one request exercises both halves of the story —
 * HasAuditLogs lifecycle auditing (Post carries the trait, so create +
 * update each queue a WorkOS audit event) and the AuditLog facade's manual
 * escape hatch. Requires an org-scoped session: the actor and organization
 * both resolve from the workos guard's claims.
 */
final class AuditLogDemoController extends Controller
{
    public function log(): JsonResponse
    {
        $post = Post::factory()->create();
        $post->update(['title' => 'Updated via audit demo']); // HasAuditLogs audits post.update

        AuditLog::log('demo.manual_action', targets: [], metadata: ['source' => 'workbench']);

        return response()->json([
            'post_id' => $post->getKey(),
            'status' => 'logged',
        ]);
    }
}
