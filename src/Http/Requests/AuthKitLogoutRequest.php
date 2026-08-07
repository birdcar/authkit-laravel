<?php

declare(strict_types=1);

namespace Authkit\Authkit\Http\Requests;

use Authkit\Authkit\Http\SessionCookie;
use Authkit\Authkit\Support\AuthkitConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use WorkOS\Service\UserManagement;
use WorkOS\SessionManager;

final class AuthKitLogoutRequest extends FormRequest
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

    public function redirect(?string $returnTo = null): RedirectResponse
    {
        $sealed = $this->cookie(SessionCookie::name());

        if (! is_string($sealed) || $sealed === '') {
            return $this->forget($returnTo);
        }

        $result = app(SessionManager::class)->authenticate(
            $sealed,
            AuthkitConfig::cookiePassword(),
            AuthkitConfig::clientId(),
            AuthkitConfig::baseUrl(),
        );

        // Nothing meaningful to log out of at WorkOS for a session that is already
        // invalid — just drop our cookie.
        if (($result['authenticated'] ?? false) !== true) {
            return $this->forget($returnTo);
        }

        $logoutUrl = app(UserManagement::class)->getLogoutUrl(
            sessionId: (string) $result['session_id'],
            returnTo: $returnTo,
        );

        return redirect()->away($logoutUrl)->withCookie(SessionCookie::forget());
    }

    private function forget(?string $returnTo): RedirectResponse
    {
        return redirect()->to($returnTo ?? '/')->withCookie(SessionCookie::forget());
    }
}
