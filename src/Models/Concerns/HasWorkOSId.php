<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Models\Concerns;

trait HasWorkOSId
{
    public function initializeHasWorkOSId(): void
    {
        $this->mergeFillable([$this->getWorkOSIdColumn()]);
    }

    public function getWorkOSIdColumn(): string
    {
        return 'workos_id';
    }

    public function getWorkOSId(): ?string
    {
        return $this->{$this->getWorkOSIdColumn()};
    }

    public function getAuthIdentifierName(): string
    {
        return $this->getWorkOSIdColumn();
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->getWorkOSId();
    }

    public static function findByWorkOSId(string $workosId): ?static
    {
        return static::where((new static)->getWorkOSIdColumn(), $workosId)->first();
    }

    public static function findOrCreateByWorkOS(array $workosUser): static
    {
        $model = new static;

        return static::firstOrCreate(
            [$model->getWorkOSIdColumn() => $workosUser['id']],
            [
                'email' => $workosUser['email'],
                'name' => trim(($workosUser['first_name'] ?? '').' '.($workosUser['last_name'] ?? '')),
            ]
        );
    }
}
