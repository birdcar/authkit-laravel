<?php

declare(strict_types=1);

namespace Authkit\Authkit\Http\Requests;

use Authkit\Authkit\Support\AuthkitConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use WorkOS\PKCEHelper;
use WorkOS\Resource\UserManagementAuthenticationProvider;
use WorkOS\Service\UserManagement;

final class AuthKitLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function redirect(?string $intendedUrl = null, ?string $organizationId = null): RedirectResponse
    {
        $pkce = PKCEHelper::generate();
        $state = bin2hex(random_bytes(16));

        // provider=authkit is NOT optional against real WorkOS: /authorize
        // requires a connection selector, and without one it errors with
        // "invalid connection selector" before any login page renders. The
        // emulator accepts selector-less requests, which is exactly how this
        // gap survived until the first real-environment login (token audit).
        $url = app(UserManagement::class)->getAuthorizationUrl(
            redirectUri: AuthkitConfig::redirectUri(),
            codeChallengeMethod: $pkce['code_challenge_method'],
            codeChallenge: $pkce['code_challenge'],
            provider: UserManagementAuthenticationProvider::Authkit,
            state: $state,
            organizationId: $organizationId,
        );

        // The PKCE verifier and state are handshake artifacts, not WorkOS session
        // state, so Laravel's own session is the right place for them.
        $this->session()->put('authkit.pkce.code_verifier', $pkce['code_verifier']);
        $this->session()->put('authkit.pkce.state', $state);

        if ($intendedUrl !== null) {
            $this->session()->put('url.intended', $intendedUrl);
        }

        return redirect()->away($url);
    }
}
