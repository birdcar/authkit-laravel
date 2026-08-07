<?php

declare(strict_types=1);

namespace Authkit\Authkit\Http\Controllers;

use Authkit\Authkit\Contracts\WorkosUser;
use Authkit\Authkit\Http\Requests\AuthKitAuthenticationRequest;
use Authkit\Authkit\Http\Requests\AuthKitLoginRequest;
use Authkit\Authkit\Http\Requests\AuthKitLogoutRequest;
use Authkit\Authkit\Http\SessionCookie;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use RuntimeException;

/**
 * A thin convenience wrapper. Every line of logic lives in the three public form
 * requests, so an app with its own controllers loses nothing by not using this.
 */
final class AuthKitController extends Controller
{
    public function login(AuthKitLoginRequest $request): RedirectResponse
    {
        $intended = $request->session()->get('url.intended');

        return $request->redirect(intendedUrl: is_string($intended) ? $intended : null);
    }

    public function callback(AuthKitAuthenticationRequest $request): RedirectResponse
    {
        $request->authenticate($this->userModel());

        $sealed = (string) $request->session()->pull(AuthKitAuthenticationRequest::SEALED_SESSION_KEY);
        $intended = (string) $request->session()->pull('url.intended', '/');

        return redirect()->to($intended)->withCookie(SessionCookie::issue($sealed));
    }

    public function logout(AuthKitLogoutRequest $request): RedirectResponse
    {
        return $request->redirect(returnTo: url('/'));
    }

    /**
     * Resolved from the guard's own provider so the model the callback *creates*
     * is always the model the guard later *retrieves* — pointing the two at
     * different classes would write rows the guard can never find.
     *
     * @return class-string<WorkosUser>
     */
    private function userModel(): string
    {
        $model = config('auth.providers.workos.model', config('auth.providers.users.model'));

        if (! is_string($model) || ! class_exists($model) || ! is_a($model, WorkosUser::class, true)) {
            throw new RuntimeException(
                'The [auth.providers.workos.model] user model must exist and implement '.WorkosUser::class
                .' (add the Authkit\Authkit\Concerns\HasWorkosUser trait to it).',
            );
        }

        return $model;
    }
}
