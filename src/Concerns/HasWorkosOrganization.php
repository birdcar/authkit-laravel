<?php

declare(strict_types=1);

namespace Authkit\Authkit\Concerns;

use Authkit\Authkit\Models\WorkosMembership;
use Authkit\Authkit\Models\WorkosOrganizationDomain;
use Authkit\Authkit\Observers\WorkosOrganizationObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Links the app's own org/team/workspace model to a WorkOS organization:
 * creating a local row queues remote creation (linked by workos_id locally and
 * external_id remotely), deleting one optionally deletes the remote org.
 *
 * @phpstan-require-extends Model
 */
trait HasWorkosOrganization
{
    protected static function bootHasWorkosOrganization(): void
    {
        // Not static::observe(): observe() constructs `new static` to enumerate
        // observable events, which fatals inside a boot hook ("may not be called
        // ... while it is being booted"). The [class, method] array form
        // registers the exact same container-resolved listeners without an
        // instance.
        static::created([WorkosOrganizationObserver::class, 'created']);
        static::deleted([WorkosOrganizationObserver::class, 'deleted']);
    }

    /**
     * The name sent to WorkOS when this organization is created remotely.
     * Override to customize name resolution — default assumes a `name`
     * attribute exists, falling back to the model's own key.
     */
    public function workosOrganizationName(): string
    {
        $name = $this->getAttribute('name');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $key = $this->getKey();

        return is_scalar($key) ? (string) $key : '';
    }

    /**
     * Duck-typed org-awareness hook Phase 9's Vault key-context resolver
     * looks for (`method_exists($model, 'workosOrganizationId')`). Returns
     * null, not throws, when this organization hasn't synced remotely yet.
     */
    public function workosOrganizationId(): ?string
    {
        $workosId = $this->getAttribute('workos_id');

        return is_string($workosId) ? $workosId : null;
    }

    /**
     * @return HasMany<WorkosOrganizationDomain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(WorkosOrganizationDomain::class, 'organization_id', 'workos_id');
    }

    /**
     * @return HasMany<WorkosMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(WorkosMembership::class, 'organization_id', 'workos_id');
    }
}
