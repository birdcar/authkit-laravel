<?php

declare(strict_types=1);

namespace Authkit\Authkit\Listeners\Workos;

use Authkit\Authkit\Events\Workos\UserDeleted;
use Authkit\Authkit\Listeners\Workos\Concerns\ResolvesProjectionModels;

/**
 * Delete-if-exists via the query builder, never findOrFail()->delete(): a
 * .deleted event for a row that was never created locally (create-then-delete
 * inside one poll gap, or a webhook race) must be a no-op, not an exception.
 */
final class DeleteUserProjection
{
    use ResolvesProjectionModels;

    public function handle(UserDeleted $event): void
    {
        $this->userProjectionModel()::query()
            ->where('workos_id', $event->resourceId())
            ->delete();
    }
}
