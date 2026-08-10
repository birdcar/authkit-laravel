<?php

declare(strict_types=1);

namespace Authkit\Authkit\Contracts;

use Authkit\Authkit\Concerns\HasWorkosResource;

/**
 * A model synced into the WorkOS FGA resource graph. Satisfied by
 * {@see HasWorkosResource}; consumer code (WorkosResourcePolicy) types
 * against this rather than probing for the trait, mirroring the
 * WorkosUser / HasWorkosUser pairing.
 */
interface WorkosResource
{
    /**
     * The Dashboard-configured resource-type slug for this model.
     */
    public function workosResourceType(): string;

    /**
     * The WorkOS organization ID that owns this resource.
     */
    public function workosResourceOrganizationId(): string;

    /**
     * The name sent to WorkOS for this resource.
     */
    public function workosResourceName(): string;

    /**
     * The external ID linking this row to its WorkOS resource.
     */
    public function workosResourceExternalId(): string;
}
