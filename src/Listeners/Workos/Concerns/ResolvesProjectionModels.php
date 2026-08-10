<?php

declare(strict_types=1);

namespace Authkit\Authkit\Listeners\Workos\Concerns;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Shared model resolution for the projection-refresh listeners. The user and
 * organization projections live on APP-owned models (configured, not
 * package-owned), so both resolutions guard the config value before use.
 */
trait ResolvesProjectionModels
{
    /**
     * Resolved through the same chain the auth callback uses
     * (AuthKitController::userModel()) so the row this projection refreshes is
     * always the row the guard retrieves.
     *
     * @return class-string<Model>
     */
    private function userProjectionModel(): string
    {
        $model = config('auth.providers.workos.model', config('auth.providers.users.model'));

        if (! is_string($model) || ! class_exists($model) || ! is_a($model, Model::class, true)) {
            // Fail loudly: a package whose guard cannot resolve a user model is
            // misconfigured at its core, and silently skipping user events
            // would let the projection drift with no operator signal.
            throw new RuntimeException(
                'The [auth.providers.workos.model] user model must exist and be an Eloquent model '
                .'for the WorkOS user projection listeners to refresh it.',
            );
        }

        return $model;
    }

    /**
     * Null when the app has not wired an organization model — org context is an
     * optional feature, so org events simply no-op then (same guard the
     * login-time projection upsert uses).
     *
     * @return class-string<Model>|null
     */
    private function organizationProjectionModel(): ?string
    {
        $model = config('authkit.organization.model');

        if (! is_string($model) || $model === '' || ! class_exists($model) || ! is_a($model, Model::class, true)) {
            return null;
        }

        return $model;
    }
}
