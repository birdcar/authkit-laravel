<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use SensitiveParameter;
use WorkOS\AuthKit\Events\UserAuthenticated;
use WorkOS\AuthKit\Events\UserLoggedOut;
use WorkOS\AuthKit\Facades\WorkOS;

class AuthController extends Controller
{
    public function login(Request $request): RedirectResponse
    {
        $organizationId = $request->query('organization_id');
        $state = $request->query('state');

        $stateArray = null;
        if ($state) {
            $stateArray = ['return_to' => $state];
        } elseif ($request->query('return_to')) {
            $stateArray = ['return_to' => $request->query('return_to')];
        }

        return redirect(WorkOS::loginUrl(
            organizationId: is_string($organizationId) ? $organizationId : null,
            state: $stateArray,
        ));
    }

    public function callback(Request $request): RedirectResponse
    {
        $code = $request->query('code');

        if (! is_string($code)) {
            return redirect()->route('login')->with('error', 'Invalid authentication callback');
        }

        $response = $this->authenticateWithCode($code);

        if ($response === null) {
            return redirect()->route('login')->with('error', 'Authentication failed');
        }

        $session = WorkOS::storeSession($response);

        $user = $this->findOrCreateUser($response);

        if ($user) {
            event(new UserAuthenticated($user, $session));
        }

        $returnTo = $this->extractReturnTo($request);

        return redirect($returnTo ?? (string) config('workos.routes.home', '/'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $session = WorkOS::session();
        $user = $request->user();
        $returnTo = $request->query('return_to');
        $returnToUrl = is_string($returnTo) ? $returnTo : null;

        // Get logout URL before destroying session (needed for cookie-based sessions)
        $logoutUrl = WorkOS::getLogoutUrl($returnToUrl);

        WorkOS::destroySession();

        if ($user) {
            event(new UserLoggedOut($user, $session));
        }

        // Redirect to WorkOS logout to clear the wos-session cookie
        if ($logoutUrl) {
            return redirect($logoutUrl);
        }

        return redirect((string) config('workos.routes.home', '/'));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function authenticateWithCode(#[SensitiveParameter] string $code): ?array
    {
        try {
            /** @var string $clientId */
            $clientId = config('workos.client_id');

            $response = WorkOS::userManagement()->authenticateWithCode(
                clientId: $clientId,
                code: $code,
            );

            // Use the raw property which contains the original API response array
            // (array) cast doesn't properly convert nested resource objects
            return $response->raw;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function findOrCreateUser(#[SensitiveParameter] array $response): ?Authenticatable
    {
        /** @var class-string $userModel */
        $userModel = config('workos.user_model', 'App\\Models\\User');

        if (! class_exists($userModel)) {
            return null;
        }

        /** @var array<string, mixed> $workosUser */
        $workosUser = $response['user'] ?? [];

        if (empty($workosUser['id'])) {
            return null;
        }

        if (method_exists($userModel, 'findOrCreateByWorkOS')) {
            $user = $userModel::findOrCreateByWorkOS($workosUser);
        } elseif (method_exists($userModel, 'findOrCreateFromWorkOS')) {
            $user = $userModel::findOrCreateFromWorkOS($workosUser);
        } else {
            $user = $userModel::updateOrCreate(
                ['workos_id' => $workosUser['id']],
                [
                    'email' => $workosUser['email'] ?? null,
                    'name' => trim(($workosUser['first_name'] ?? '').' '.($workosUser['last_name'] ?? '')),
                ]
            );
        }

        return $user instanceof Authenticatable ? $user : null;
    }

    protected function extractReturnTo(Request $request): ?string
    {
        $state = $request->query('state');

        if (is_string($state) && json_validate($state)) {
            $decoded = json_decode($state, true);
            if (is_array($decoded) && isset($decoded['return_to'])) {
                return (string) $decoded['return_to'];
            }
        }

        return null;
    }
}
