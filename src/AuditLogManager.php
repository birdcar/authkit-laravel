<?php

declare(strict_types=1);

namespace Authkit\Authkit;

use Authkit\Authkit\AuditLogs\Exceptions\InvalidRetentionPeriodException;
use Authkit\Authkit\AuditLogs\Jobs\CreateAuditLogEventJob;
use Authkit\Authkit\AuditLogs\Jobs\PollAuditLogExportJob;
use Authkit\Authkit\AuditLogs\Support\AuditActorResolver;
use Authkit\Authkit\AuditLogs\Support\MetadataSanitizer;
use Authkit\Authkit\Contracts\WorkosClientManager;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use WorkOS\PaginatedResponse;
use WorkOS\RequestOptions;
use WorkOS\Resource\AuditLogExport;
use WorkOS\Resource\AuditLogSchema;
use WorkOS\Resource\AuditLogSchemaActorInput;
use WorkOS\Resource\AuditLogSchemaTargetInput;
use WorkOS\Resource\AuditLogsRetention;
use WorkOS\Resource\PaginationOrder;

/**
 * Facade-fronted audit-logs manager. log() is the write path — context is
 * resolved eagerly in the calling process, then the wire call runs on a
 * queued job. The schema/export/retention methods are deliberate thin
 * passthroughs returning the SDK's own resource DTOs unwrapped (spec-phase-6
 * §4 design note): dashboard-adjacent management calls, not runtime-hot
 * paths, at the contract's usable-core depth.
 */
class AuditLogManager
{
    public function __construct(
        private readonly WorkosClientManager $clients,
        private readonly AuditActorResolver $contextResolver,
    ) {}

    /**
     * Record an audit event. Actor and organization resolve from the current
     * workos-guard session unless overridden; the wire call is queued with an
     * Idempotency-Key generated here, so queue-driven retries of the same
     * dispatch are safe while independent calls never collide.
     *
     * @param  array<int, array{id: string, type: string, name?: string|null, metadata?: array<string, mixed>|null}>  $targets
     * @param  array<string, mixed>  $metadata
     * @param  array{id?: string, type?: string, name?: string}|null  $actor
     */
    public function log(
        string $action,
        array $targets,
        array $metadata = [],
        ?string $organizationId = null,
        ?array $actor = null,
        ?string $idempotencyKey = null,
    ): void {
        $resolved = $this->contextResolver->resolve($organizationId, $actor);
        $sanitized = MetadataSanitizer::sanitize($metadata, context: $action);

        CreateAuditLogEventJob::dispatch(
            action: $action,
            occurredAt: Carbon::now()->toDateTimeImmutable(),
            actor: $resolved,
            targets: $targets,
            metadata: $sanitized,
            idempotencyKey: $idempotencyKey ?? (string) Str::uuid(),
        );
    }

    /**
     * Register (or update) the schema future events for $actionName must
     * satisfy — mismatches surface as AuditLogSchemaMismatchException when
     * the queued createEvent call is rejected.
     *
     * @param  array<int, AuditLogSchemaTargetInput>  $targets
     * @param  array<string, mixed>|null  $metadata
     */
    public function createSchema(
        string $actionName,
        array $targets,
        ?AuditLogSchemaActorInput $actor = null,
        ?array $metadata = null,
        ?RequestOptions $options = null,
    ): AuditLogSchema {
        return $this->clients->client()->auditLogs()->createSchema(
            $actionName,
            $targets,
            $actor,
            $metadata,
            $options,
        );
    }

    public function listActions(
        ?string $before = null,
        ?string $after = null,
        ?int $limit = null,
        PaginationOrder $order = PaginationOrder::Desc,
        ?RequestOptions $options = null,
    ): PaginatedResponse {
        return $this->clients->client()->auditLogs()->listActions(
            $before,
            $after,
            $limit,
            $order,
            $options,
        );
    }

    public function listActionSchemas(
        string $actionName,
        ?string $before = null,
        ?string $after = null,
        ?int $limit = null,
        PaginationOrder $order = PaginationOrder::Desc,
        ?RequestOptions $options = null,
    ): PaginatedResponse {
        return $this->clients->client()->auditLogs()->listActionSchemas(
            $actionName,
            $before,
            $after,
            $limit,
            $order,
            $options,
        );
    }

    /**
     * Mint an export and start the package's poll loop: PollAuditLogExportJob
     * re-checks the export until it leaves `pending`, then dispatches
     * AuditLogExportReady or AuditLogExportFailed — listen for those instead
     * of hand-rolling a poll.
     *
     * @param  array<int, string>|null  $actions
     * @param  array<int, string>|null  $actors  Deprecated upstream; use $actorNames.
     * @param  array<int, string>|null  $actorNames
     * @param  array<int, string>|null  $actorIds
     * @param  array<int, string>|null  $targets
     */
    public function createExport(
        string $organizationId,
        DateTimeImmutable $rangeStart,
        DateTimeImmutable $rangeEnd,
        ?array $actions = null,
        ?array $actors = null,
        ?array $actorNames = null,
        ?array $actorIds = null,
        ?array $targets = null,
        ?RequestOptions $options = null,
    ): AuditLogExport {
        $export = $this->clients->client()->auditLogs()->createExport(
            $organizationId,
            $rangeStart,
            $rangeEnd,
            $actions,
            $actors,
            $actorNames,
            $actorIds,
            $targets,
            $options,
        );

        PollAuditLogExportJob::dispatch($export->id);

        return $export;
    }

    /**
     * Fetch an export by id. The returned `url` expires 10 minutes after
     * mint/refetch — never cache the URL value, only the export id, and
     * re-fetch immediately before use to regenerate a fresh URL.
     */
    public function getExport(string $auditLogExportId, ?RequestOptions $options = null): AuditLogExport
    {
        return $this->clients->client()->auditLogs()->getExport($auditLogExportId, $options);
    }

    public function getRetention(string $organizationId): AuditLogsRetention
    {
        return $this->clients->client()->auditLogs()->getOrganizationAuditLogsRetention($organizationId);
    }

    public function setRetention(string $organizationId, int $days): AuditLogsRetention
    {
        if (! in_array($days, [30, 365], true)) {
            throw InvalidRetentionPeriodException::forDays($days);
        }

        return $this->clients->client()->auditLogs()->updateOrganizationAuditLogsRetention($organizationId, $days);
    }
}
