<?php

declare(strict_types=1);

namespace Authkit\Authkit\Authorization;

use Authkit\Authkit\Contracts\WorkosClientManager;
use WorkOS\RequestOptions;
use WorkOS\Resource\AuthorizationResource;

/**
 * FGA resource sync, resolved via Authkit::resources(). Deliberately just
 * create + delete-by-external-id: the HasWorkosResource trait's footprint.
 * Deletion is keyed by external ID because the projection boundary forbids
 * storing the WorkOS resource's internal id in a local column.
 *
 * Resource TYPES are Dashboard-configured only — there is no create-type API,
 * and no local way to validate a type slug before createResource fails on it
 * (spec-phase-5 Failure Mode 10).
 *
 * Mutating methods accept the SDK's own ?RequestOptions so a direct caller
 * retrying can carry an idempotency key; the trait's automatic model-event
 * hooks have no caller to supply one (spec-phase-5 Failure Mode 12).
 */
final class ResourceManager
{
    public function __construct(private readonly WorkosClientManager $clients) {}

    public function create(
        string $externalId,
        string $name,
        string $resourceTypeSlug,
        string $organizationId,
        ?string $description = null,
        ?RequestOptions $options = null,
    ): AuthorizationResource {
        return $this->clients->client()->authorization()->createResource(
            externalId: $externalId,
            name: $name,
            resourceTypeSlug: $resourceTypeSlug,
            organizationId: $organizationId,
            description: $description,
            options: $options,
        );
    }

    public function deleteByExternalId(
        string $organizationId,
        string $resourceTypeSlug,
        string $externalId,
        bool $cascadeDelete = false,
        ?RequestOptions $options = null,
    ): void {
        // null, not false: the SDK omits the cascade_delete query parameter
        // entirely when it is null, matching the API's own default.
        $this->clients->client()->authorization()->deleteResourceByExternalId(
            organizationId: $organizationId,
            resourceTypeSlug: $resourceTypeSlug,
            externalId: $externalId,
            cascadeDelete: $cascadeDelete ? true : null,
            options: $options,
        );
    }
}
