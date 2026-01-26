<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Tests\Fixtures;

use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;

/**
 * Minimal test user class for unit tests that need HasWorkOSPermissions trait.
 */
class TestUser
{
    use HasWorkOSPermissions;

    public function __construct(
        private string $id = 'user_123',
    ) {}

    public function getAuthIdentifier(): string
    {
        return $this->id;
    }
}
