<?php

declare(strict_types=1);

namespace Authkit\Authkit\Testing\Fakes;

use Authkit\Authkit\AuditLogManager;
use Authkit\Authkit\AuditLogs\Exceptions\InvalidRetentionPeriodException;
use Authkit\Authkit\AuditLogs\Support\AuditActorResolver;
use Authkit\Authkit\AuditLogs\Support\MetadataSanitizer;
use Authkit\Authkit\AuditLogs\Support\ResolvedAuditContext;
use BadMethodCallException;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use WorkOS\PaginatedResponse;
use WorkOS\RequestOptions;
use WorkOS\Resource\AuditLogExport;
use WorkOS\Resource\AuditLogExportState;
use WorkOS\Resource\AuditLogSchema;
use WorkOS\Resource\AuditLogSchemaActorInput;
use WorkOS\Resource\AuditLogSchemaTarget;
use WorkOS\Resource\AuditLogSchemaTargetInput;
use WorkOS\Resource\AuditLogsRetention;
use WorkOS\Resource\PaginationOrder;

/**
 * An in-memory {@see AuditLogManager}: log() records instead of queueing the
 * wire-call job, exports live in a local registry, and retention is a map.
 *
 * Context resolution stays REAL: log() still runs {@see AuditActorResolver}
 * (so the actor comes from the acting session, and a missing organization
 * still throws MissingOrganizationContextException) and
 * {@see MetadataSanitizer} (so assertions see exactly the metadata production
 * would send, redactions included).
 */
final class AuditLogFake extends AuditLogManager
{
    /**
     * @var list<array{
     *     action: string,
     *     targets: array<int, array{id: string, type: string, name?: string|null, metadata?: array<string, mixed>|null}>,
     *     metadata: array<string, mixed>,
     *     organization_id: string,
     *     actor: ResolvedAuditContext,
     *     idempotency_key: string|null,
     * }>
     */
    private array $logged = [];

    /** @var array<string, AuditLogExport> */
    private array $exports = [];

    /** @var list<array{organization_id: string, range_start: DateTimeImmutable, range_end: DateTimeImmutable, export_id: string}> */
    private array $exportRequests = [];

    /** @var array<string, int> */
    private array $retention = [];

    /** @var list<array{action_name: string, targets: array<int, AuditLogSchemaTargetInput>, actor: AuditLogSchemaActorInput|null, metadata: array<string, mixed>|null}> */
    private array $schemas = [];

    /** @var list<mixed> */
    private array $scriptedActions = [];

    /** @var list<mixed> */
    private array $scriptedActionSchemas = [];

    private int $sequence = 0;

    public function __construct()
    {
        // Deliberately no parent call: the fake never touches the WorkOS
        // client, and every inherited method that would is overridden below.
    }

    /**
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
        $resolved = app(AuditActorResolver::class)->resolve($organizationId, $actor);

        $this->logged[] = [
            'action' => $action,
            'targets' => $targets,
            'metadata' => MetadataSanitizer::sanitize($metadata, context: $action),
            'organization_id' => $resolved->organizationId,
            'actor' => $resolved,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    /**
     * @param  (callable(array{action: string, targets: array<int, array{id: string, type: string, name?: string|null, metadata?: array<string, mixed>|null}>, metadata: array<string, mixed>, organization_id: string, actor: ResolvedAuditContext, idempotency_key: string|null}): bool)|null  $callback
     */
    public function assertLogged(string $action, ?callable $callback = null): void
    {
        $matches = array_filter(
            $this->logged,
            static fn (array $entry): bool => $entry['action'] === $action
                && ($callback === null || $callback($entry)),
        );

        Assert::assertNotEmpty($matches, sprintf(
            'Expected an audit log event [%s]%s but none matched. %s',
            $action,
            $callback !== null ? ' passing the given callback' : '',
            $this->describeLogged(),
        ));
    }

    public function assertNotLogged(string $action): void
    {
        $matches = array_filter($this->logged, static fn (array $entry): bool => $entry['action'] === $action);

        Assert::assertEmpty($matches, sprintf('Unexpected audit log event [%s]. %s', $action, $this->describeLogged()));
    }

    public function assertNothingLogged(): void
    {
        Assert::assertEmpty($this->logged, sprintf('Expected no audit log events at all. %s', $this->describeLogged()));
    }

    /**
     * Every recorded log() call, oldest first.
     *
     * @return list<array{action: string, targets: array<int, array{id: string, type: string, name?: string|null, metadata?: array<string, mixed>|null}>, metadata: array<string, mixed>, organization_id: string, actor: ResolvedAuditContext, idempotency_key: string|null}>
     */
    public function recordedLogs(): array
    {
        return $this->logged;
    }

    /**
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
        $this->schemas[] = [
            'action_name' => $actionName,
            'targets' => $targets,
            'actor' => $actor,
            'metadata' => $metadata,
        ];

        return new AuditLogSchema(
            object: 'audit_log_schema',
            version: 1,
            targets: array_map(
                static fn (AuditLogSchemaTargetInput $target): AuditLogSchemaTarget => new AuditLogSchemaTarget($target->type, $target->metadata),
                $targets,
            ),
            createdAt: new DateTimeImmutable,
            metadata: $metadata,
        );
    }

    /**
     * @param  (callable(array{action_name: string, targets: array<int, AuditLogSchemaTargetInput>, actor: AuditLogSchemaActorInput|null, metadata: array<string, mixed>|null}): bool)|null  $callback
     */
    public function assertSchemaCreated(string $actionName, ?callable $callback = null): void
    {
        $matches = array_filter(
            $this->schemas,
            static fn (array $schema): bool => $schema['action_name'] === $actionName
                && ($callback === null || $callback($schema)),
        );

        Assert::assertNotEmpty($matches, sprintf(
            'Expected an audit log schema for [%s]%s but none matched (%d schema registrations recorded).',
            $actionName,
            $callback !== null ? ' passing the given callback' : '',
            count($this->schemas),
        ));
    }

    /**
     * Fixture items served by {@see listActions()}.
     *
     * @param  list<mixed>  $items
     */
    public function scriptActions(array $items): self
    {
        $this->scriptedActions = $items;

        return $this;
    }

    /**
     * Fixture items served by {@see listActionSchemas()}.
     *
     * @param  list<mixed>  $items
     */
    public function scriptActionSchemas(array $items): self
    {
        $this->scriptedActionSchemas = $items;

        return $this;
    }

    public function listActions(
        ?string $before = null,
        ?string $after = null,
        ?int $limit = null,
        PaginationOrder $order = PaginationOrder::Desc,
        ?RequestOptions $options = null,
    ): PaginatedResponse {
        return new PaginatedResponse($this->scriptedActions, []);
    }

    public function listActionSchemas(
        string $actionName,
        ?string $before = null,
        ?string $after = null,
        ?int $limit = null,
        PaginationOrder $order = PaginationOrder::Desc,
        ?RequestOptions $options = null,
    ): PaginatedResponse {
        return new PaginatedResponse($this->scriptedActionSchemas, []);
    }

    /**
     * Mints a PENDING synthetic export and records the request. No poll job
     * is dispatched — drive the lifecycle explicitly with
     * {@see markExportReady()} when the test needs a ready export.
     *
     * @param  array<int, string>|null  $actions
     * @param  array<int, string>|null  $actors
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
        $id = 'audit_log_export_fake_'.++$this->sequence;
        $now = (new DateTimeImmutable)->format(DateTimeInterface::RFC3339_EXTENDED);

        $export = AuditLogExport::fromArray([
            'object' => 'audit_log_export',
            'id' => $id,
            'state' => AuditLogExportState::Pending->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->exports[$id] = $export;
        $this->exportRequests[] = [
            'organization_id' => $organizationId,
            'range_start' => $rangeStart,
            'range_end' => $rangeEnd,
            'export_id' => $id,
        ];

        return $export;
    }

    /**
     * Transition a fake export to READY with a downloadable-looking URL.
     */
    public function markExportReady(string $auditLogExportId, ?string $url = null): AuditLogExport
    {
        $export = $this->getExport($auditLogExportId);

        $ready = AuditLogExport::fromArray([
            'object' => 'audit_log_export',
            'id' => $export->id,
            'state' => AuditLogExportState::Ready->value,
            'created_at' => $export->createdAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'updated_at' => (new DateTimeImmutable)->format(DateTimeInterface::RFC3339_EXTENDED),
            'url' => $url ?? "https://fake.workos.test/exports/{$export->id}.csv",
        ]);

        return $this->exports[$export->id] = $ready;
    }

    public function getExport(string $auditLogExportId, ?RequestOptions $options = null): AuditLogExport
    {
        return $this->exports[$auditLogExportId] ?? throw new InvalidArgumentException(
            "No fake audit log export [{$auditLogExportId}] exists. Create one first with createExport().",
        );
    }

    /**
     * @param  (callable(array{organization_id: string, range_start: DateTimeImmutable, range_end: DateTimeImmutable, export_id: string}): bool)|null  $callback
     */
    public function assertExportRequested(?callable $callback = null): void
    {
        $matches = array_filter(
            $this->exportRequests,
            static fn (array $request): bool => $callback === null || $callback($request),
        );

        Assert::assertNotEmpty($matches, sprintf(
            'Expected an audit log export request%s but none matched (%d recorded).',
            $callback !== null ? ' passing the given callback' : '',
            count($this->exportRequests),
        ));
    }

    public function getRetention(string $organizationId): AuditLogsRetention
    {
        return new AuditLogsRetention($this->retention[$organizationId] ?? 30);
    }

    public function setRetention(string $organizationId, int $days): AuditLogsRetention
    {
        if (! in_array($days, [30, 365], true)) {
            throw InvalidRetentionPeriodException::forDays($days);
        }

        $this->retention[$organizationId] = $days;

        return new AuditLogsRetention($days);
    }

    /**
     * The parent's __call answers unknown methods with "call Authkit::fake()
     * first" — actively misleading here, where the fake IS bound. A typo'd
     * assertion must read as what it is: an undefined method.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        throw new BadMethodCallException(sprintf('Method %s::%s does not exist.', self::class, $method));
    }

    private function describeLogged(): string
    {
        if ($this->logged === []) {
            return 'No audit log events were recorded.';
        }

        $actions = array_map(static fn (array $entry): string => $entry['action'], $this->logged);

        return 'Recorded events: '.implode(', ', $actions).'.';
    }
}
