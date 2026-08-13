<?php

declare(strict_types=1);

use Authkit\Authkit\AuditLogManager;
use Authkit\Authkit\AuditLogs\Exceptions\InvalidRetentionPeriodException;
use Authkit\Authkit\AuditLogs\Exceptions\MissingOrganizationContextException;
use Authkit\Authkit\Facades\AuditLog;
use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Testing\Fakes\AuditLogFake;
use PHPUnit\Framework\AssertionFailedError;
use Workbench\App\Models\Organization;
use Workbench\Database\Factories\UserFactory;
use WorkOS\Resource\AuditLogExport;
use WorkOS\Resource\AuditLogExportState;
use WorkOS\Resource\AuditLogSchemaTargetInput;

beforeEach(function (): void {
    $this->migratePackageDatabase();
});

function auditLogFake(): AuditLogFake
{
    $fake = new AuditLogFake;

    app()->instance(AuditLogManager::class, $fake);

    return $fake;
}

it('records log calls with the real actor resolution from the acting session', function (): void {
    $fake = auditLogFake();
    $user = UserFactory::new()->create(['workos_id' => 'user_actor', 'name' => 'Ada Lovelace']);
    $organization = Organization::query()->createQuietly(['name' => 'Acme', 'workos_id' => 'org_acme']);

    Authkit::actingAs($user, ['organization' => $organization]);

    AuditLog::log('task.created', [['id' => 'task_1', 'type' => 'task']], ['title' => 'Ship it']);

    $fake->assertLogged('task.created');
    $fake->assertLogged('task.created', fn (array $entry): bool => $entry['organization_id'] === 'org_acme'
        && $entry['actor']->actorId === 'user_actor'
        && $entry['metadata'] === ['title' => 'Ship it']
        && $entry['targets'][0]['id'] === 'task_1');
});

it('still throws without organization context exactly like production', function (): void {
    auditLogFake();

    expect(fn () => AuditLog::log('task.created', [['id' => 'task_1', 'type' => 'task']]))
        ->toThrow(MissingOrganizationContextException::class);
});

it('asserts logged, not logged, and nothing logged with readable failures', function (): void {
    $fake = auditLogFake();

    $fake->assertNothingLogged();
    $fake->assertNotLogged('task.created');

    expect(fn () => $fake->assertLogged('task.created'))
        ->toThrow(AssertionFailedError::class, 'No audit log events were recorded');

    AuditLog::log('task.created', [['id' => 'task_1', 'type' => 'task']], organizationId: 'org_direct');

    $fake->assertLogged('task.created');

    expect(fn () => $fake->assertNotLogged('task.created'))
        ->toThrow(AssertionFailedError::class, 'Recorded events: task.created')
        ->and(fn () => $fake->assertLogged('task.deleted'))
        ->toThrow(AssertionFailedError::class, 'Recorded events: task.created');
});

it('captures export requests and drives the export lifecycle locally', function (): void {
    $fake = auditLogFake();

    $export = AuditLog::createExport(
        'org_acme',
        new DateTimeImmutable('-7 days'),
        new DateTimeImmutable('now'),
    );

    expect($export)->toBeInstanceOf(AuditLogExport::class)
        ->and($export->state)->toBe(AuditLogExportState::Pending)
        ->and($export->url)->toBeNull();

    $fake->assertExportRequested();
    $fake->assertExportRequested(fn (array $request): bool => $request['organization_id'] === 'org_acme');

    $ready = $fake->markExportReady($export->id);

    expect($ready->state)->toBe(AuditLogExportState::Ready)
        ->and($ready->url)->toContain($export->id)
        ->and(AuditLog::getExport($export->id)->state)->toBe(AuditLogExportState::Ready);
});

it('throws with guidance for unknown exports', function (): void {
    auditLogFake();

    expect(fn (): AuditLogExport => AuditLog::getExport('audit_log_export_missing'))
        ->toThrow(InvalidArgumentException::class, 'createExport()');
});

it('reports a typo’d assertion as an undefined method, not as a missing fake', function (): void {
    $fake = auditLogFake();

    // @phpstan-ignore method.notFound (deliberate typo — the runtime message is the subject under test)
    expect(fn () => $fake->assertLoged('task.created'))
        ->toThrow(BadMethodCallException::class, 'does not exist');
});

it('records idempotency keys for retry-path assertions', function (): void {
    $fake = auditLogFake();

    AuditLog::log('task.created', [['id' => 'task_1', 'type' => 'task']], organizationId: 'org_direct', idempotencyKey: 'retry-safe-1');

    $fake->assertLogged('task.created', fn (array $entry): bool => $entry['idempotency_key'] === 'retry-safe-1');
});

it('echoes schemas and serves scripted action lists', function (): void {
    $fake = auditLogFake();

    $schema = AuditLog::createSchema('task.created', [new AuditLogSchemaTargetInput('task')]);

    expect($schema->version)->toBe(1)
        ->and($schema->targets[0]->type)->toBe('task');

    $fake->assertSchemaCreated('task.created', fn (array $registered): bool => $registered['targets'][0]->type === 'task');

    expect(AuditLog::listActions()->data)->toBe([]);

    $fake->scriptActions([['name' => 'task.created']])
        ->scriptActionSchemas([['version' => 1]]);

    expect(AuditLog::listActions()->data)->toHaveCount(1)
        ->and(AuditLog::listActionSchemas('task.created')->data)->toHaveCount(1);
});

it('stores retention in memory with production validation', function (): void {
    auditLogFake();

    expect(AuditLog::getRetention('org_acme')->retentionPeriodInDays)->toBe(30);

    AuditLog::setRetention('org_acme', 365);

    expect(AuditLog::getRetention('org_acme')->retentionPeriodInDays)->toBe(365)
        ->and(fn () => AuditLog::setRetention('org_acme', 90))
        ->toThrow(InvalidRetentionPeriodException::class);
});
