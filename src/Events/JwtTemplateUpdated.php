<?php

declare(strict_types=1);

namespace Authkit\Authkit\Events;

/**
 * Fired synchronously by JwtTemplateManager::update() on every successful
 * template write — a package-native event (not a WorkOS-sourced one, so it
 * does not live under Events\Workos). Listen to wire your own alerting: a
 * template edit changes what rides inside every subsequently-minted access
 * token, which the sealed session cookie (4KB ceiling), zero-HTTP RBAC
 * claims, and the Pennant feature_flags claim all depend on.
 */
final class JwtTemplateUpdated
{
    public function __construct(
        /** The template content before this write ('' when none existed). */
        public readonly string $previousContent,
        public readonly string $newContent,
    ) {}
}
