<?php

declare(strict_types=1);

namespace Authkit\Authkit\Attributes;

use Attribute;

/**
 * Declarative per-lifecycle action-name overrides for HasAuditLogs. Only
 * non-null arguments override — unnamed lifecycles keep the slug-based
 * default (e.g. "post.create"). A model's $auditActions property, when
 * present, wins over this attribute entirely.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AuditActions
{
    public function __construct(
        public readonly ?string $create = null,
        public readonly ?string $update = null,
        public readonly ?string $delete = null,
        public readonly ?string $archive = null,
        public readonly ?string $restore = null,
    ) {}
}
