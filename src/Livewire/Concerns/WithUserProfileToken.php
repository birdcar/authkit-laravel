<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Concerns;

/**
 * Overrides WithWidgetToken for UserProfile widgets.
 *
 * UserProfile endpoints are user-scoped, not organization-scoped.
 * They use the session's access token directly rather than a widget token
 * generated via Widgets::getToken() (which requires an organization context).
 */
trait WithUserProfileToken
{
    private ?string $widgetToken = null;

    private ?int $tokenExpiresAt = null;

    protected function getWidgetToken(string $scope): string
    {
        if ($this->widgetToken !== null && $this->tokenExpiresAt !== null && $this->tokenExpiresAt > time()) {
            return $this->widgetToken;
        }

        $session = workos()->validSession();

        if ($session === null) {
            throw new \RuntimeException('No active WorkOS session. User Profile widget requires an authenticated user.');
        }

        $this->widgetToken = $session->accessToken;
        $this->tokenExpiresAt = $session->expiresAt->getTimestamp();

        return $this->widgetToken;
    }

    protected function clearWidgetToken(): void
    {
        $this->widgetToken = null;
        $this->tokenExpiresAt = null;
    }
}
