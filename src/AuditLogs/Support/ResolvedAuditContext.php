<?php

declare(strict_types=1);

namespace Authkit\Authkit\AuditLogs\Support;

/**
 * Actor + organization context resolved eagerly in the calling process — the
 * HTTP request or console invocation that triggered the audit event — and
 * carried into the queued job as a plain serialized value. By the time the
 * job runs on a worker there is no authenticated user or bound request left
 * to read, so resolution can never be deferred to handle() (spec-phase-6
 * §4.2's load-bearing design choice).
 */
final readonly class ResolvedAuditContext
{
    public function __construct(
        public string $actorId,
        public string $actorType,
        public ?string $actorName,
        public string $organizationId,
        public string $location,
        public ?string $userAgent,
    ) {}
}
