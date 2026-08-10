<?php

declare(strict_types=1);

namespace Authkit\Authkit\AuditLogs\Exceptions;

use RuntimeException;

/**
 * Local validation failure for AuditLogManager::setRetention() — WorkOS only
 * supports 30- or 365-day retention, so anything else fails here before a
 * network round-trip that would end in a server-side 4xx anyway.
 */
final class InvalidRetentionPeriodException extends RuntimeException
{
    public static function forDays(int $days): self
    {
        return new self(sprintf(
            'Audit log retention must be 30 or 365 days; [%d] given.',
            $days,
        ));
    }
}
