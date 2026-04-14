<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Concerns;

trait WithWidgetToken
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
            throw new \RuntimeException('No active WorkOS session. Widget token requires an authenticated user.');
        }

        if ($session->organizationId === null) {
            throw new \RuntimeException('No organization context. Widget token requires an organization.');
        }

        $response = workos()->widgets()->createToken(
            organizationId: $session->organizationId,
            userId: $session->userId,
            scopes: [$scope],
        );

        $this->widgetToken = $response->token;
        $this->tokenExpiresAt = time() + 3500;

        return $this->widgetToken;
    }

    protected function clearWidgetToken(): void
    {
        $this->widgetToken = null;
        $this->tokenExpiresAt = null;
    }
}
