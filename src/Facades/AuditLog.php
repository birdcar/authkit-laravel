<?php

declare(strict_types=1);

namespace Authkit\Authkit\Facades;

use Authkit\Authkit\AuditLogManager;
use Authkit\Authkit\Testing\Fakes\AuditLogFake;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void log(string $action, array<int, array{id: string, type: string, name?: string|null, metadata?: array<string, mixed>|null}> $targets, array<string, mixed> $metadata = [], ?string $organizationId = null, array{id?: string, type?: string, name?: string}|null $actor = null, ?string $idempotencyKey = null)
 * @method static \WorkOS\Resource\AuditLogSchema createSchema(string $actionName, array<int, \WorkOS\Resource\AuditLogSchemaTargetInput> $targets, ?\WorkOS\Resource\AuditLogSchemaActorInput $actor = null, array<string, mixed>|null $metadata = null, ?\WorkOS\RequestOptions $options = null)
 * @method static \WorkOS\PaginatedResponse listActions(?string $before = null, ?string $after = null, ?int $limit = null, \WorkOS\Resource\PaginationOrder $order = \WorkOS\Resource\PaginationOrder::Desc, ?\WorkOS\RequestOptions $options = null)
 * @method static \WorkOS\PaginatedResponse listActionSchemas(string $actionName, ?string $before = null, ?string $after = null, ?int $limit = null, \WorkOS\Resource\PaginationOrder $order = \WorkOS\Resource\PaginationOrder::Desc, ?\WorkOS\RequestOptions $options = null)
 * @method static \WorkOS\Resource\AuditLogExport createExport(string $organizationId, \DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd, array<int, string>|null $actions = null, array<int, string>|null $actors = null, array<int, string>|null $actorNames = null, array<int, string>|null $actorIds = null, array<int, string>|null $targets = null, ?\WorkOS\RequestOptions $options = null)
 * @method static \WorkOS\Resource\AuditLogExport getExport(string $auditLogExportId, ?\WorkOS\RequestOptions $options = null)
 * @method static \WorkOS\Resource\AuditLogsRetention getRetention(string $organizationId)
 * @method static \WorkOS\Resource\AuditLogsRetention setRetention(string $organizationId, int $days)
 * @method static void assertLogged(string $action, callable|null $callback = null)
 * @method static void assertNotLogged(string $action)
 * @method static void assertNothingLogged()
 * @method static void assertSchemaCreated(string $actionName, callable|null $callback = null)
 * @method static void assertExportRequested(callable|null $callback = null)
 * @method static \WorkOS\Resource\AuditLogExport markExportReady(string $auditLogExportId, ?string $url = null)
 *
 * @see AuditLogManager
 * @see AuditLogFake for the assert* / markExportReady testing surface (bound by Authkit::fake(['audit-log']))
 */
class AuditLog extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AuditLogManager::class;
    }
}
