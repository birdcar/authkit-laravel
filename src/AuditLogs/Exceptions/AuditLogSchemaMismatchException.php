<?php

declare(strict_types=1);

namespace Authkit\Authkit\AuditLogs\Exceptions;

use RuntimeException;

/**
 * A WorkOS 4xx rejection of a createEvent call — the event's action, target
 * types, or metadata keys do not satisfy the schema registered for the action
 * (see AuditLogManager::createSchema()). Named and greppable instead of a
 * generic failed-job entry.
 */
final class AuditLogSchemaMismatchException extends RuntimeException {}
