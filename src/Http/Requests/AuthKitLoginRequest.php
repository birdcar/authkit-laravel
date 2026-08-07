<?php

declare(strict_types=1);

namespace Authkit\Authkit\Http\Requests;

use Authkit\Authkit\Support\AuthkitConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use WorkOS\PKCEHelper;
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

    public function redirect(?string $intendedUrl = null): RedirectResponse
    {
        $pkce = PKCEHelper::generate();
        $state = bin2hex(random_bytes(16));

        $url = app(UserManagement::class)->getAuthorizationUrl(
            redirectUri: AuthkitConfig::redirectUri(),
            codeChallengeMethod: $pkce['code_challenge_method'],
            codeChallenge: $pkce['code_challenge'],
            state: $state,
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
