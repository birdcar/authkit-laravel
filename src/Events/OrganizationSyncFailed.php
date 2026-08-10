<?php

declare(strict_types=1);

namespace Authkit\Authkit\Events;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Acknowledgment event dispatched from either org-sync job's failed() hook —
 * the consuming app's observability seam for "local org exists with no remote
 * counterpart (or vice versa)".
 */
final readonly class OrganizationSyncFailed
{
    public function __construct(
        public ?Model $organization,
        public ?Throwable $exception,
        public ?string $workosOrganizationId = null,
    ) {}
}
