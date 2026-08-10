<?php

declare(strict_types=1);

namespace Authkit\Authkit\AuditLogs\Jobs;

use Authkit\Authkit\AuditLogs\Exceptions\AuditLogSchemaMismatchException;
use Authkit\Authkit\AuditLogs\Support\ResolvedAuditContext;
use Authkit\Authkit\Contracts\WorkosClientManager;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use WorkOS\Exception\BadRequestException;
use WorkOS\Exception\UnprocessableEntityException;
use WorkOS\RequestOptions;
use WorkOS\Resource\AuditLogEvent;
use WorkOS\Resource\AuditLogEventActor;
use WorkOS\Resource\AuditLogEventContext;
use WorkOS\Resource\AuditLogEventTarget;

/**
 * The audit-event wire call, off the request path. The constructor carries an
 * already-resolved ResolvedAuditContext — never a deferred resolution — and
 * the Idempotency-Key minted at log() time, so queue-driven retries of this
 * same dispatch replay against WorkOS's 24h idempotency cache instead of
 * duplicating the event.
 */
class CreateAuditLogEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * @param  array<int, array{id: string, type: string, name?: string|null, metadata?: array<string, mixed>|null}>  $targets
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $action,
        public readonly DateTimeImmutable $occurredAt,
        public readonly ResolvedAuditContext $actor,
        public readonly array $targets,
        public readonly array $metadata,
        public readonly string $idempotencyKey,
    ) {}

    public function handle(WorkosClientManager $clients): void
    {
        $event = new AuditLogEvent(
            action: $this->action,
            occurredAt: $this->occurredAt,
            actor: new AuditLogEventActor(
                id: $this->actor->actorId,
                type: $this->actor->actorType,
                name: $this->actor->actorName,
            ),
            targets: array_map(
                fn (array $target): AuditLogEventTarget => new AuditLogEventTarget(
                    id: $target['id'],
                    type: $target['type'],
                    name: $target['name'] ?? null,
                    metadata: $target['metadata'] ?? null,
                ),
                $this->targets,
            ),
            context: new AuditLogEventContext(
                location: $this->actor->location,
                userAgent: $this->actor->userAgent,
            ),
            metadata: $this->metadata !== [] ? $this->metadata : null,
        );

        try {
            $clients->client()->auditLogs()->createEvent(
                $this->actor->organizationId,
                $event,
                new RequestOptions(idempotencyKey: $this->idempotencyKey),
            );
        } catch (BadRequestException|UnprocessableEntityException $e) {
            Log::error('authkit: audit log event rejected (schema mismatch)', [
                'action' => $this->action,
                'organization_id' => $this->actor->organizationId,
                'status' => $e->statusCode,
                'error' => $e->error,
                'body' => $e->rawBody,
            ]);

            throw new AuditLogSchemaMismatchException($e->getMessage(), previous: $e);
        }
    }
}
