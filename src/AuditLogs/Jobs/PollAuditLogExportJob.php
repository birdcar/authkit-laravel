<?php

declare(strict_types=1);

namespace Authkit\Authkit\AuditLogs\Jobs;

use Authkit\Authkit\AuditLogs\Events\AuditLogExportFailed;
use Authkit\Authkit\AuditLogs\Events\AuditLogExportReady;
use Authkit\Authkit\Contracts\WorkosClientManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use WorkOS\Resource\AuditLogExport;
use WorkOS\Resource\AuditLogExportState;

/**
 * Self-requeuing poll for the inherently async export resource
 * (pending → ready/error/expired). Dispatched by
 * AuditLogManager::createExport() so consumers listen for
 * AuditLogExportReady/AuditLogExportFailed instead of hand-rolling the
 * identical loop; bounded by audit_logs.export_poll_max_attempts so a stuck
 * `pending` export can never requeue forever.
 */
class PollAuditLogExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly string $exportId,
        public readonly int $attempt = 1,
    ) {}

    public function handle(WorkosClientManager $clients): void
    {
        $export = $clients->client()->auditLogs()->getExport($this->exportId);

        match ($export->state) {
            AuditLogExportState::Ready => event(new AuditLogExportReady($export)),
            AuditLogExportState::Error,
            AuditLogExportState::Expired => event(new AuditLogExportFailed($export, reason: $export->state->value)),
            AuditLogExportState::Pending => $this->requeueOrGiveUp($export),
        };
    }

    private function requeueOrGiveUp(AuditLogExport $export): void
    {
        $maxAttempts = (int) config('authkit.audit_logs.export_poll_max_attempts', 30);

        if ($this->attempt >= $maxAttempts) {
            event(new AuditLogExportFailed($export, reason: 'timeout'));

            return;
        }

        self::dispatch($this->exportId, $this->attempt + 1)
            ->delay(now()->addSeconds((int) config('authkit.audit_logs.export_poll_interval_seconds', 10)));
    }
}
