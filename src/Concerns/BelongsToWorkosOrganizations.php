<?php

declare(strict_types=1);

namespace Authkit\Authkit\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use RuntimeException;

/**
 * Gives the app's User model an organizations() relation resolving through the
 * workos_memberships projection, joined entirely on WorkOS ID strings (never
 * local numeric IDs) so it works regardless of which concrete org model class
 * the app configures.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToWorkosOrganizations
{
    /**
     * @return BelongsToMany<Model, $this>
     */
    public function organizations(): BelongsToMany
    {
        // Resolved at call time, not trait-boot time: config is guaranteed to
        // be loaded by the time any request calls ->organizations(), but not
        // necessarily when Eloquent boots model classes in some artisan
        // contexts.
        $organizationModel = config('authkit.organization.model');

        if (! is_string($organizationModel) || ! class_exists($organizationModel) || ! is_a($organizationModel, Model::class, true)) {
            throw new RuntimeException(
                'The [authkit.organization.model] config value must name an Eloquent model class '
                .'to use the organizations() relation (set AUTHKIT_ORGANIZATION_MODEL).',
            );
        }

        return $this->belongsToMany(
            related: $organizationModel,
            table: 'workos_memberships',
            foreignPivotKey: 'user_id',
            relatedPivotKey: 'organization_id',
            parentKey: 'workos_id',
            relatedKey: 'workos_id',
        )->withPivot(['workos_id', 'role', 'status']);
    }
}
