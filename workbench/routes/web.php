<?php

use Authkit\Authkit\Auth\WorkosApiKeyActor;
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
