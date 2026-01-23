<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Audit\Contracts;

interface Auditable
{
    /**
     * @return array{type: string, id: string, name: ?string}
     */
    public function toAuditTarget(): array;
}
