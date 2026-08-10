<?php

declare(strict_types=1);

namespace Authkit\Authkit\Pipes\Data;

use WorkOS\Resource\DataIntegrationConfigurationResponse;

/**
 * One provider's organization-level configuration. clientSecretLastFour only
 * ever carries the WorkOS-redacted last four characters — the SDK never
 * returns the full secret, so exposing it here adds no secret-handling
 * surface.
 */
final readonly class ProviderConfigurationData
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $providerSlug,
        public string $name,
        public bool $enabled,
        /** @var array<string>|null */
        public ?array $scopes,
        /** @var array<string, string> */
        public array $config,
        public bool $hasOrganizationCredentials,
        public ?string $clientId,
        public ?string $clientSecretLastFour,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromResponse(DataIntegrationConfigurationResponse $response): self
    {
        return new self(
            id: $response->id,
            organizationId: $response->organizationId,
            providerSlug: $response->slug,
            name: $response->name,
            enabled: $response->enabled,
            scopes: $response->scopes,
            config: $response->config,
            hasOrganizationCredentials: $response->credentials->hasCredentials ?? false,
            clientId: $response->credentials?->clientId,
            clientSecretLastFour: $response->credentials?->clientSecretLastFour,
            createdAt: $response->createdAt,
            updatedAt: $response->updatedAt,
        );
    }
}
