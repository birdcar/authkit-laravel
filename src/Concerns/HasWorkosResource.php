<?php

declare(strict_types=1);

namespace Authkit\Authkit\Concerns;

use Authkit\Authkit\Authorization\FgaChecker;
use Authkit\Authkit\Authorization\ResourceTarget;
use Authkit\Authkit\Contracts\WorkosResource;
use Authkit\Authkit\Facades\Authkit;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

/**
 * Satisfies {@see WorkosResource}: syncs the model into the WorkOS FGA
 * resource graph — creating a row creates the remote authorization resource
 * (external_id = the model's own key), deleting one deletes it by external
 * ID. No WorkOS id is ever stored locally — the projection boundary forbids
 * new WorkOS-shaped columns.
 *
 * Named gotcha (spec-phase-5 Failure Mode 8): Eloquent fires `deleted` for
 * soft deletes too, so a model combining SoftDeletes with this trait deletes
 * the remote FGA resource while the local row remains restorable. Apps
 * combining both must override this trait's boot hooks themselves.
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements WorkosResource
 */
trait HasWorkosResource
{
    /**
     * The Dashboard-configured resource-type slug for this model. Must match
     * an existing type exactly — there is no API to create or validate one,
     * so a typo here fails at the first createResource call in production
     * (spec-phase-5 Failure Mode 10).
     */
    abstract public function workosResourceType(): string;

    /**
     * The WorkOS organization ID that owns this resource. Default assumes an
     * `organization` relation exposing a `workos_id` column; override if the
     * model resolves its org differently.
     */
    public function workosResourceOrganizationId(): string
    {
        $organization = $this->getAttribute('organization');
        $workosId = $organization instanceof Model ? $organization->getAttribute('workos_id') : null;

        if (! is_string($workosId) || $workosId === '') {
            throw new LogicException(sprintf(
                '%s has no organization relation carrying a workos_id; override workosResourceOrganizationId() to resolve the owning WorkOS organization.',
                static::class,
            ));
        }

        return $workosId;
    }

    /**
     * The name sent to WorkOS for this resource. Default assumes a `name`
     * attribute, falling back to the model's own key.
     */
    public function workosResourceName(): string
    {
        $name = $this->getAttribute('name');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        return $this->workosResourceExternalId();
    }

    /**
     * The external ID linking this row to its WorkOS resource — the model's
     * own primary key, per the projection-boundary linking convention.
     */
    public function workosResourceExternalId(): string
    {
        $key = $this->getKey();

        return is_scalar($key) ? (string) $key : '';
    }

    /**
     * Override to nest this model's FGA resource under another
     * WorkosResource model's resource, e.g. `return $this->folder;`. WorkOS
     * enforces a single parent per resource and a five-level hierarchy cap
     * (configured per resource type in the Dashboard — there is no API/DSL
     * for it), which is why this is one overridable hook rather than a
     * composable set of traits.
     */
    public function workosParentResource(): ?Model
    {
        return null;
    }

    private function workosParentResourceTarget(): ?ResourceTarget
    {
        $parent = $this->workosParentResource();

        if ($parent === null) {
            return null;
        }

        if (! $parent instanceof WorkosResource) {
            throw new InvalidArgumentException(sprintf(
                '%s::workosParentResource() must return a model implementing %s (use HasWorkosResource), got %s.',
                static::class,
                WorkosResource::class,
                $parent::class,
            ));
        }

        return ResourceTarget::byExternalId(
            $parent->workosResourceExternalId(),
            $parent->workosResourceType(),
        );
    }

    protected static function bootHasWorkosResource(): void
    {
        static::created(function (self $model): void {
            Authkit::resources()->create(
                externalId: $model->workosResourceExternalId(),
                name: $model->workosResourceName(),
                resourceTypeSlug: $model->workosResourceType(),
                organizationId: $model->workosResourceOrganizationId(),
                parentResource: $model->workosParentResourceTarget(),
            );

            // A resource appearing in (or moving within) the hierarchy shifts
            // effective-permission inheritance; a config-guarded no-op while
            // the opt-in FGA cache is disabled.
            app(FgaChecker::class)->forgetCache();
        });

        static::deleted(function (self $model): void {
            Authkit::resources()->deleteByExternalId(
                organizationId: $model->workosResourceOrganizationId(),
                resourceTypeSlug: $model->workosResourceType(),
                externalId: $model->workosResourceExternalId(),
            );

            app(FgaChecker::class)->forgetCache();
        });
    }
}
