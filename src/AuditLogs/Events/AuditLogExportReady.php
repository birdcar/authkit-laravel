<?php

declare(strict_types=1);

namespace Authkit\Authkit\AuditLogs\Events;

use WorkOS\Resource\AuditLogExport;

/**
 * Package-internal lifecycle event (synthesized by PollAuditLogExportJob,
 * not decoded from a WorkOS event payload — hence not Events\Workos\*): the
 * export reached `ready`. The export's `url` expires 10 minutes after
 * mint/refetch — never persist the URL from this event, only $export->id,
 * and re-fetch via AuditLog::getExport($id) immediately before use.
 */
final class AuditLogExportReady
{
    public function __construct(public readonly AuditLogExport $export) {}
}
