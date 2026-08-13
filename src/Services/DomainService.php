<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Services;

use WorkOS\WorkOS;

class DomainService
{
    public function __construct(
        private readonly WorkOS $client,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function create(string $organizationId, string $domain): array
    {
        $result = $this->client->organizationDomains()->createOrganizationDomain(
            domain: $domain,
            organizationId: $organizationId,
        );

        return $result->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        $result = $this->client->organizationDomains()->getOrganizationDomain(id: $id);

        return $result->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(string $id): array
    {
        $result = $this->client->organizationDomains()->verifyOrganizationDomain(id: $id);

        return $result->toArray();
    }

    public function delete(string $id): void
    {
        $this->client->organizationDomains()->deleteOrganizationDomain(id: $id);
    }
}
