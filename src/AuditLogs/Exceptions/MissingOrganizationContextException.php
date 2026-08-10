<?php

declare(strict_types=1);

namespace Authkit\Authkit\AuditLogs\Exceptions;

use RuntimeException;

/**
 * Thrown when an audit log event cannot resolve a WorkOS organization —
 * loud and named, because WorkOS rejects events without an organization_id
 * anyway, and silently guessing one would misattribute the audit trail.
 */
final class MissingOrganizationContextException extends RuntimeException
{
    public static function forAuditLog(): self
    {
        return new self(
            'No WorkOS organization context could be resolved for an audit log event. '
            .'Audit events require an organization: authenticate under the workos guard with a session '
            .'that carries an org_id claim, or pass organizationId explicitly to AuditLog::log().',
        );
    }
}
