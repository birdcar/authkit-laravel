<?php

declare(strict_types=1);

namespace Authkit\Authkit\AuditLogs\Support;

use Illuminate\Support\Facades\Log;

/**
 * Enforces the WorkOS audit-event metadata limits (50 keys, 500 characters
 * per string value) locally, before the request leaves the process — a
 * truncated-but-recorded event beats losing the whole audit record to a 4xx.
 * Truncation is never silent: a warning names the action so oversized
 * auditMetadata() implementations are discoverable.
 */
final class MetadataSanitizer
{
    public const int MAX_KEYS = 50;

    public const int MAX_VALUE_CHARACTERS = 500;

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function sanitize(array $metadata, string $context): array
    {
        $droppedKeys = 0;

        if (count($metadata) > self::MAX_KEYS) {
            $droppedKeys = count($metadata) - self::MAX_KEYS;
            $metadata = array_slice($metadata, 0, self::MAX_KEYS, preserve_keys: true);
        }

        $clippedValues = 0;

        foreach ($metadata as $key => $value) {
            if (is_string($value) && mb_strlen($value) > self::MAX_VALUE_CHARACTERS) {
                $metadata[$key] = mb_substr($value, 0, self::MAX_VALUE_CHARACTERS);
                $clippedValues++;
            }
        }

        if ($droppedKeys > 0 || $clippedValues > 0) {
            Log::warning('authkit: audit log metadata truncated to WorkOS limits', [
                'context' => $context,
                'dropped_keys' => $droppedKeys,
                'clipped_values' => $clippedValues,
            ]);
        }

        return $metadata;
    }
}
