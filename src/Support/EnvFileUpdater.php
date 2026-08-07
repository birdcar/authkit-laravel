<?php

declare(strict_types=1);

namespace Authkit\Authkit\Support;

final class EnvFileUpdater
{
    /**
     * @param  array<string, string>  $keys
     * @return array<int, string> keys actually appended
     */
    public function ensureKeys(string $path, array $keys): array
    {
        if (! is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        $toAppend = [];

        foreach ($keys as $key => $value) {
            if (preg_match('/^'.preg_quote($key, '/').'=/m', $contents) === 1) {
                continue; // already present — this is the idempotency guard
            }

            $toAppend[$key] = $value;
        }

        if ($toAppend === []) {
            return [];
        }

        $lines = array_map(
            static fn (string $k, string $v): string => "{$k}={$v}",
            array_keys($toAppend),
            array_values($toAppend),
        );

        // Silenced because the return value is what we act on: a read-only target
        // should surface as the command's own message, not a raw PHP warning
        // (which phpunit.xml.dist's failOnWarning would also turn into a failure).
        $written = @file_put_contents($path, rtrim($contents)."\n\n".implode("\n", $lines)."\n");

        if ($written === false) {
            return []; // read-only target — report nothing appended rather than lying
        }

        return array_keys($toAppend);
    }
}
