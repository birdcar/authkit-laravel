<?php

use Authkit\Authkit\Auth\WorkosApiKeyActor;
use Authkit\Authkit\Facades\Authkit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

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

Route::get('/depth-extensions/groups', function (Request $request) {
    $organizationId = $request->query('organization_id', config('workbench.demo_organization_id'));

    abort_unless(is_string($organizationId) && $organizationId !== '', 422, 'Pass ?organization_id=org_... to list groups.');

    return Authkit::groups()->list(organizationId: $organizationId)->data;
});
