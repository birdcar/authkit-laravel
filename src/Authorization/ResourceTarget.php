<?php

declare(strict_types=1);

namespace Authkit\Authkit\Authorization;

use LogicException;
use WorkOS\Service\ResourceTargetByExternalId;
use WorkOS\Service\ResourceTargetById;

/**
 * Package-boundary DTO wrapping the SDK's ResourceTargetById|ResourceTargetByExternalId
 * input union so consumers of RoleManager and Authkit::check() never name SDK
 * service classes in their own signatures.
 */
final class ResourceTarget
{
    private function __construct(
        private readonly ?string $resourceId,
        private readonly ?string $externalId,
        private readonly ?string $typeSlug,
    ) {}

    public static function byId(string $resourceId): self
    {
        return new self($resourceId, null, null);
    }

    public static function byExternalId(string $externalId, string $typeSlug): self
    {
        return new self(null, $externalId, $typeSlug);
    }

    /**
     * @internal package-boundary conversion only
     */
    public function toSdkTarget(): ResourceTargetById|ResourceTargetByExternalId
    {
        if ($this->resourceId !== null) {
            return new ResourceTargetById($this->resourceId);
        }

        if ($this->externalId === null || $this->typeSlug === null) {
            // Unreachable through the named constructors; guards the union for
            // static analysis rather than expressing a real runtime state.
            throw new LogicException('ResourceTarget must be constructed via byId() or byExternalId().');
        }

        return new ResourceTargetByExternalId($this->externalId, $this->typeSlug);
    }
}
