<?php

declare(strict_types=1);

use Authkit\Authkit\AuditLogs\Events\AuditLogExportFailed;
use Authkit\Authkit\AuditLogs\Events\AuditLogExportReady;
use Authkit\Authkit\AuditLogs\Jobs\PollAuditLogExportJob;
use Authkit\Authkit\Facades\AuditLog;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use WorkOS\Resource\AuditLogExport;
use WorkOS\Resource\AuditLogExportState;

// Test path: MockHandler — emulate 0.6.0 auto-transitions exports straight to
// `ready`, so the pending→ready/error/expired poll state machine this suite
// exercises is only observable against scripted responses. The sync queue
// connection collapses the job's delayed self-requeue into an immediate
// recursive run, which makes each sequence deterministic.

uses(UsesWorkosMockHandler::class);

function auditExportResponse(string $state, ?string $url = null): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(array_filter([
        'object' => 'audit_log_export',
        'id' => 'audit_log_export_01TEST',
        'state' => $state,
        'created_at' => '2026-01-01T00:00:00.000Z',
        'updated_at' => '2026-01-01T00:00:00.000Z',
        'url' => $url,
    ], fn (mixed $value): bool => $value !== null)));
}

describe('AuditLogExport', function (): void {
    it('mints an export and immediately dispatches the poll job for it', function (): void {
        Queue::fake();

        $this->fakeWorkosResponses([auditExportResponse('pending')]);

        $export = AuditLog::createExport(
            'org_x',
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
            new DateTimeImmutable('2026-02-01T00:00:00Z'),
            actions: ['post.create'],
            actorNames: ['Alice Anderson'],
        );

        $request = $this->workosRequestHistory[0]['request'];
        $body = json_decode((string) $request->getBody(), true);

        expect($export)->toBeInstanceOf(AuditLogExport::class)
            ->and($export->state)->toBe(AuditLogExportState::Pending)
            ->and($request->getMethod())->toBe('POST')
            ->and($request->getUri()->getPath())->toBe('/audit_logs/exports')
            ->and($body['organization_id'])->toBe('org_x')
            ->and($body['actions'])->toBe(['post.create'])
            ->and($body['actor_names'])->toBe(['Alice Anderson']);

        Queue::assertPushed(PollAuditLogExportJob::class, fn (PollAuditLogExportJob $job): bool => $job->exportId === 'audit_log_export_01TEST' && $job->attempt === 1);
    });

    it('dispatches AuditLogExportReady when the export is ready on the first poll', function (): void {
        Event::fake([AuditLogExportReady::class, AuditLogExportFailed::class]);

        $this->fakeWorkosResponses([auditExportResponse('ready', url: 'https://exports.workos.test/x.csv')]);

        PollAuditLogExportJob::dispatch('audit_log_export_01TEST');

        Event::assertDispatched(AuditLogExportReady::class, fn (AuditLogExportReady $event): bool => $event->export->url === 'https://exports.workos.test/x.csv');
        Event::assertNotDispatched(AuditLogExportFailed::class);
    });

    it('re-polls through pending states until the export is ready', function (): void {
        Event::fake([AuditLogExportReady::class, AuditLogExportFailed::class]);

        $this->fakeWorkosResponses([
            auditExportResponse('pending'),
            auditExportResponse('pending'),
            auditExportResponse('ready', url: 'https://exports.workos.test/x.csv'),
        ]);

        PollAuditLogExportJob::dispatch('audit_log_export_01TEST');

        expect($this->workosRequestHistory)->toHaveCount(3);

        Event::assertDispatchedTimes(AuditLogExportReady::class, 1);
        Event::assertNotDispatched(AuditLogExportFailed::class);
    });

    it('dispatches AuditLogExportFailed carrying the terminal state as the reason', function (string $state): void {
        Event::fake([AuditLogExportReady::class, AuditLogExportFailed::class]);

        $this->fakeWorkosResponses([auditExportResponse($state)]);

        PollAuditLogExportJob::dispatch('audit_log_export_01TEST');

        Event::assertDispatched(AuditLogExportFailed::class, fn (AuditLogExportFailed $event): bool => $event->reason === $state);
        Event::assertNotDispatched(AuditLogExportReady::class);
    })->with(['error', 'expired']);

    it('gives up with reason timeout after export_poll_max_attempts pending polls', function (): void {
        config()->set('authkit.audit_logs.export_poll_max_attempts', 3);

        Event::fake([AuditLogExportReady::class, AuditLogExportFailed::class]);

        $this->fakeWorkosResponses([
            auditExportResponse('pending'),
            auditExportResponse('pending'),
            auditExportResponse('pending'),
        ]);

        PollAuditLogExportJob::dispatch('audit_log_export_01TEST');

        expect($this->workosRequestHistory)->toHaveCount(3);

        Event::assertDispatched(AuditLogExportFailed::class, fn (AuditLogExportFailed $event): bool => $event->reason === 'timeout');
        Event::assertNotDispatched(AuditLogExportReady::class);
    });

    it('fetches an export by id through the passthrough (fresh URL each fetch)', function (): void {
        $this->fakeWorkosResponses([auditExportResponse('ready', url: 'https://exports.workos.test/fresh.csv')]);

        $export = AuditLog::getExport('audit_log_export_01TEST');

        expect($export->state)->toBe(AuditLogExportState::Ready)
            ->and($export->url)->toBe('https://exports.workos.test/fresh.csv')
            ->and($this->workosRequestHistory[0]['request']->getUri()->getPath())
            ->toBe('/audit_logs/exports/audit_log_export_01TEST');
    });
});
