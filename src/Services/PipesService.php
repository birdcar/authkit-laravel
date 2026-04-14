<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Services;

use WorkOS\WorkOS;

class PipesService
{
    public function __construct(
        private readonly WorkOS $client,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function listProviders(string $userId, ?string $organizationId = null): array
    {
        $result = $this->client->pipes()->listUserDataProviders(
            userId: $userId,
            organizationId: $organizationId,
        );

        return $result->toArray();
    }

    public function getAuthorizationUrl(
        string $slug,
        string $userId,
        ?string $returnTo = null,
        ?string $organizationId = null,
    ): string {
        $result = $this->client->pipes()->authorizeDataIntegration(
            slug: $slug,
            userId: $userId,
            organizationId: $organizationId,
            returnTo: $returnTo ?? (string) config('workos.routes.home', '/'),
        );

        return $result->url;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConnectedAccount(
        string $userId,
        string $slug,
        ?string $organizationId = null,
    ): array {
        $result = $this->client->pipes()->getUserConnectedAccount(
            userId: $userId,
            slug: $slug,
            organizationId: $organizationId,
        );

        return $result->toArray();
    }

    public function deleteConnectedAccount(
        string $userId,
        string $slug,
        ?string $organizationId = null,
    ): void {
        $this->client->pipes()->deleteUserConnectedAccount(
            userId: $userId,
            slug: $slug,
            organizationId: $organizationId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getAccessToken(
        string $userId,
        string $slug,
        ?string $organizationId = null,
    ): array {
        $result = $this->client->pipes()->createDataIntegrationToken(
            slug: $slug,
            userId: $userId,
            organizationId: $organizationId,
        );

        return $result->toArray();
    }
}
