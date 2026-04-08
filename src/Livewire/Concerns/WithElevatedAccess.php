<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Concerns;

trait WithElevatedAccess
{
    private ?string $elevatedToken = null;

    private ?int $elevatedExpiresAt = null;

    protected function acquireElevatedToken(string $verificationMethod): ?string
    {
        if ($this->elevatedToken !== null && $this->elevatedExpiresAt !== null && $this->elevatedExpiresAt > time()) {
            return $this->elevatedToken;
        }

        /** @var array<string, mixed> $result */
        $result = $this->widgetPost('/UserProfile/verify', [
            'verificationMethod' => $verificationMethod,
        ]);

        if (isset($result['elevatedAccessToken'])) {
            /** @var string $token */
            $token = $result['elevatedAccessToken'];
            $this->elevatedToken = $token;
            $this->elevatedExpiresAt = time() + 540;

            return $this->elevatedToken;
        }

        return null;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    protected function elevatedHeaders(array $headers = []): array
    {
        if ($this->elevatedToken !== null) {
            $headers['x-elevated-access-token'] = $this->elevatedToken;
        }

        return $headers;
    }

    protected function hasElevatedAccess(): bool
    {
        return $this->elevatedToken !== null
            && $this->elevatedExpiresAt !== null
            && $this->elevatedExpiresAt > time();
    }

    protected function clearElevatedToken(): void
    {
        $this->elevatedToken = null;
        $this->elevatedExpiresAt = null;
    }
}
