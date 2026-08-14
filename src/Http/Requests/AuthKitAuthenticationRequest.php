<?php

declare(strict_types=1);

namespace Authkit\Authkit\Http\Requests;

use Authkit\Authkit\Contracts\WorkosUser;
use Authkit\Authkit\Events\Login;
use Authkit\Authkit\Exceptions\AuthKitCallbackFailedException;
use Authkit\Authkit\Exceptions\AuthKitStateMismatchException;
use Authkit\Authkit\Support\AuthkitConfig;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use WorkOS\Service\UserManagement;
use WorkOS\SessionManager;

final class AuthKitAuthenticationRequest extends FormRequest
{
    /**
     * Where the freshly sealed session is handed to whoever owns the response.
     * Sealing is this class's job; attaching a cookie is the caller's. A custom
     * controller must pull this key — leaving it in the session parks a
     * refresh-token-bearing blob in the session store.
     */
    public const string SEALED_SESSION_KEY = 'authkit._sealed_session';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ];
    }

    /**
     * An OAuth error callback carries `error` (+ optional `error_description`)
     * and no code, so it must be intercepted BEFORE the code/state rules run:
     * a validation redirect would send the browser back where it came from —
     * the authorize URL — which re-runs the redirect and loops forever.
     */
    protected function prepareForValidation(): void
    {
        $error = $this->query('error');

        if (is_string($error) && $error !== '') {
            $description = $this->query('error_description');

            throw new AuthKitCallbackFailedException($error, is_string($description) ? $description : null);
        }
    }

    /**
     * A callback without a code/state pair (bookmarked, replayed, hand-typed)
     * gets the same friendly landing as an OAuth error — never the default
     * redirect-back, which loops for the same reason as above.
     */
    protected function failedValidation(Validator $validator): never
    {
        throw new AuthKitCallbackFailedException('invalid_callback');
    }

    /**
     * @param  class-string<WorkosUser>  $userModelClass
     */
    public function authenticate(string $userModelClass): WorkosUser
    {
        // Pulled, not read: a replayed callback finds the state already consumed
        // and fails before the code is exchanged a second time.
        $expectedState = $this->session()->pull('authkit.pkce.state');
        $codeVerifier = $this->session()->pull('authkit.pkce.code_verifier');
        $state = $this->validated('state');

        if (! is_string($expectedState) || ! is_string($state) || ! hash_equals($expectedState, $state)) {
            throw new AuthKitStateMismatchException;
        }

        $code = $this->validated('code');
        $cookiePassword = AuthkitConfig::cookiePassword();

        $response = app(UserManagement::class)->authenticateWithCode(
            code: is_string($code) ? $code : '',
            codeVerifier: is_string($codeVerifier) ? $codeVerifier : null,
        );

        $user = $userModelClass::findOrCreateForWorkosUser($response->user);

        // The `user` payload is not optional in practice: SessionManager::refresh()
        // rejects any sealed session that lacks it.
        $sealed = SessionManager::sealSessionFromAuthResponse(
            accessToken: $response->accessToken,
            refreshToken: $response->refreshToken,
            cookiePassword: $cookiePassword,
            user: $response->user->toArray(),
            impersonator: $response->impersonator?->toArray(),
        );

        $user->setWorkosImpersonator($response->impersonator?->toArray());

        // The pre-login session ID is attacker-fixable; authentication state lives
        // in the sealed cookie, but the Laravel session still carries the CSRF
        // token and app state and should not survive a privilege change.
        $this->session()->regenerate();

        event(new Login($user, $response));

        return tap($user, fn () => $this->session()->put(self::SEALED_SESSION_KEY, $sealed));
    }
}
