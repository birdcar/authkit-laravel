<?php

declare(strict_types=1);

use Authkit\Authkit\Http\Controllers\AuthKitController;
use Authkit\Authkit\Http\Controllers\OAuthProtectedResourceMetadataController;
use Authkit\Authkit\Http\Controllers\SwitchOrganizationController;
use Illuminate\Support\Facades\Route;

// RFC 9728 fixes this path — registered outside the prefixed group, without
// the configurable middleware stack, and ABOVE the routes.enabled gate: that
// toggle exists so apps can own the auth-flow routes, while this document's
// own feature toggle is the MCP config itself — it soft-404s while
// authkit_domain/resource_indicator are unconfigured (spec-phase-10 F10).
Route::get('/.well-known/oauth-protected-resource', OAuthProtectedResourceMetadataController::class)
    ->name('authkit.oauth-protected-resource');

if (! config('authkit.routes.enabled', true)) {
    return;
}

/** @var array<int, string> $middleware */
$middleware = config('authkit.routes.middleware', ['web']);

// The `web` group (or any group with session middleware) is a hard requirement:
// the PKCE verifier and state live in Laravel's session between login and callback.
Route::middleware($middleware)
    ->prefix((string) config('authkit.routes.prefix', 'authkit'))
    ->group(function (): void {
        Route::get((string) config('authkit.routes.paths.login', 'login'), [AuthKitController::class, 'login'])
            ->name('authkit.login');

        Route::get((string) config('authkit.routes.paths.callback', 'callback'), [AuthKitController::class, 'callback'])
            ->name('authkit.callback');

        // POST so it sits behind VerifyCsrfToken: a GET-triggerable logout is a
        // real CSRF surface (`<img src="/authkit/logout">`).
        Route::post((string) config('authkit.routes.paths.logout', 'logout'), [AuthKitController::class, 'logout'])
            ->name('authkit.logout');

        // POST for the same CSRF reason: switching orgs rotates the sealed
        // session cookie, which no GET should be able to trigger cross-site.
        Route::post(
            (string) config('authkit.routes.paths.switch_organization', 'organizations/{organizationId}/switch'),
            SwitchOrganizationController::class,
        )->name('authkit.switch-org');
    });
