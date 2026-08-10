<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// One line, zero SDK references: POST workos/webhooks behind WorkOS-Signature
// verification, dispatching the same typed/generic Laravel events the
// authkit:work poller emits. Guarded because static-analysis bootstraps load
// this file without the package provider's boot() having registered the macro.
if (Route::hasMacro('workosWebhooks')) {
    Route::workosWebhooks('workos/webhooks');
}
