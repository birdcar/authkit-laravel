<?php

use Authkit\Authkit\Auth\WorkosApiKeyActor;
use Authkit\Authkit\Facades\Authkit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\AdminPortalDemoController;
use Workbench\App\Http\Controllers\ApiKeyDemoController;
use Workbench\App\Http\Controllers\AuditLogDemoController;
use Workbench\App\Http\Controllers\AuthorizationDemoController;
use Workbench\App\Http\Controllers\ConnectMcpDemoController;
use Workbench\App\Http\Controllers\DashboardController;
use Workbench\App\Http\Controllers\FeatureFlagDemoController;
use Workbench\App\Http\Controllers\PipesController;
use Workbench\App\Http\Controllers\VaultDemoController;

Route::get('/', function () {
    return view('welcome');
});

// API-key guard playground: curl with a WorkOS API key and watch the resolved
// principal and its Gate decision live, e.g.
//   curl -H "Authorization: Bearer <key>" -H "Accept: application/json" \
//     http://localhost:8000/api-keys/whoami
Route::middleware('auth:authkit-key')->get('/api-keys/whoami', function (Request $request) {
    $user = $request->user();

    return [
        'principal' => $user::class,
        'permissions' => $user instanceof WorkosApiKeyActor
            ? $user->permissions
            : $user->apiKeyPermissions(),
        'can_ping' => Gate::allows('ping'),
    ];
});

// One line, zero SDK references: POST workos/webhooks behind WorkOS-Signature
// verification, dispatching the same typed/generic Laravel events the
// authkit:work poller emits. Guarded because static-analysis bootstraps load
// this file without the package provider's boot() having registered the macro.
if (Route::hasMacro('workosWebhooks')) {
    Route::workosWebhooks('workos/webhooks');
}

// Depth-extension playgrounds. The demo organization comes from the query
// string (?organization_id=org_...), falling back to a workbench config key,
// so the routes work against any seeded environment, e.g.
//   curl "http://localhost:8000/depth-extensions/invitations?organization_id=org_x"
//   curl -X POST -d "email=new@acme.com&organization_id=org_x" \
//     http://localhost:8000/depth-extensions/invitations
Route::get('/depth-extensions/invitations', function (Request $request) {
    $organizationId = $request->query('organization_id', config('workbench.demo_organization_id'));

    return Authkit::invitations()->list(
        organizationId: is_string($organizationId) ? $organizationId : null,
    )->data;
});

Route::post('/depth-extensions/invitations', function (Request $request) {
    $organizationId = $request->input('organization_id', config('workbench.demo_organization_id'));

    return Authkit::invitations()->send(
        email: $request->string('email')->toString(),
        organizationId: is_string($organizationId) ? $organizationId : null,
    );
});

// Pipes playground: live read-throughs to WorkOS (no local projection).
// GET /pipes lists the session user's connected accounts via the trait;
// GET /pipes/{provider}/token fetches an auto-refreshed access token,
// redirecting to the reauthorization URL when the grant has drifted. The
// provider-config passthrough takes its organization from the query/body,
// matching the depth-extensions convention above.
Route::middleware('auth:workos')->group(function (): void {
    Route::get('/pipes', [PipesController::class, 'index'])->name('pipes.index');
    Route::get('/pipes/{provider}/token', [PipesController::class, 'token'])->name('pipes.token');
});

Route::get('/pipes-providers', [PipesController::class, 'providers'])->name('pipes.providers');
Route::post('/pipes-providers/{provider}', [PipesController::class, 'configureProvider'])->name('pipes.providers.update');

// Phase 13 demo surface: every scope-table area gets at least one route,
// all calling package APIs only (the WorkbenchZeroSdkReference test keeps
// this file — and every other workbench file — free of direct SDK use).
// The dashboard is the post-login hub a human quickstart trial checks.
Route::middleware('auth:workos')->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('demo.dashboard');
    Route::post('/demo/audit-log', [AuditLogDemoController::class, 'log'])->name('demo.audit.log');
    Route::get('/demo/portal/{intent}', [AdminPortalDemoController::class, 'link'])->name('demo.portal.link');
    Route::get('/demo/rbac', [AuthorizationDemoController::class, 'rbac'])->name('demo.rbac.check');
    Route::get('/demo/fga/{resourceExternalId}', [AuthorizationDemoController::class, 'fga'])->name('demo.fga.check');
    Route::get('/demo/flags', [FeatureFlagDemoController::class, 'check'])->name('demo.flags.check');
    Route::post('/demo/api-keys', [ApiKeyDemoController::class, 'issue'])->name('demo.api-keys.issue');
    Route::delete('/demo/api-keys/{apiKeyId}', [ApiKeyDemoController::class, 'revoke'])->name('demo.api-keys.revoke');
    Route::get('/demo/vault', [VaultDemoController::class, 'roundTrip'])->name('demo.vault.round-trip');
    Route::get('/demo/connect', [ConnectMcpDemoController::class, 'applications'])->name('demo.connect.applications');
});

Route::get('/depth-extensions/groups', function (Request $request) {
    $organizationId = $request->query('organization_id', config('workbench.demo_organization_id'));

    abort_unless(is_string($organizationId) && $organizationId !== '', 422, 'Pass ?organization_id=org_... to list groups.');

    return Authkit::groups()->list(organizationId: $organizationId)->data;
});
