<?php

declare(strict_types=1);

namespace Authkit\Authkit\CorsOrigins;

use Authkit\Authkit\Contracts\WorkosClientManager;
use WorkOS\PaginatedResponse;
use WorkOS\Resource\CORSOriginResponse;

/**
 * CORS origin passthrough, resolved via Authkit::corsOrigins(). List and
 * create ONLY: workos-php v9.1.0 exposes no delete endpoint anywhere in the
 * SDK — a hard SDK ceiling, not a design choice (spec-phase-12 Deviations 1).
 * Add delete(string $id) here if a later SDK release ships one.
 */
final class CorsOriginManager
{
    public function __construct(private readonly WorkosClientManager $clients) {}

    /**
     * List the environment's CORS origins. Items are CORSOriginResponse
     * instances.
     */
    public function list(?string $before = null, ?string $after = null, ?int $limit = null): PaginatedResponse
    {
        return $this->clients->client()->userManagement()->listCorsOrigins(
            before: $before,
            after: $after,
            limit: $limit,
        );
    }

    public function create(string $origin): CORSOriginResponse
    {
        return $this->clients->client()->userManagement()->createCorsOrigin($origin);
    }
}
