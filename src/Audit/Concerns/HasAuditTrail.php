<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Audit\Concerns;

trait HasAuditTrail
{
    /**
     * @return array{type: string, id: string, name: ?string}
     */
    public function toAuditTarget(): array
    {
        return [
            'type' => $this->getAuditType(),
            'id' => (string) $this->getKey(),
            'name' => $this->getAuditName(),
        ];
    }

    protected function getAuditType(): string
    {
        return strtolower(class_basename($this));
    }

    protected function getAuditName(): ?string
    {
        /** @var ?string */
        return $this->name ?? $this->title ?? null;
    }
}
