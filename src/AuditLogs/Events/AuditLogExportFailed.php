<?php

declare(strict_types=1);

namespace Authkit\Authkit\AuditLogs\Events;

use WorkOS\Resource\AuditLogExport;

/**
 * Package-internal lifecycle event (synthesized by PollAuditLogExportJob, not
 * decoded from a WorkOS event payload): the export ended in `error` or
 * `expired`, or the poll exhausted audit_logs.export_poll_max_attempts —
 * $reason is the state value or 'timeout' respectively.
 */
final class AuditLogExportFailed
{
    public function __construct(
        public readonly AuditLogExport $export,
        public readonly string $reason,
    ) {}
}
