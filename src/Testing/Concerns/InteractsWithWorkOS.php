<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Testing\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use WorkOS\AuthKit\Testing\WorkOSFake;
use WorkOS\AuthKit\WorkOS;

trait InteractsWithWorkOS
{
    /**
     * @param  array<string>  $roles
     * @param  array<string>  $permissions
     */
    protected function actingAsWorkOS(
        Authenticatable $user,
        array $roles = [],
        array $permissions = [],
        ?string $organizationId = null,
    ): WorkOSFake {
        return WorkOS::actingAs($user, $roles, $permissions, $organizationId);
    }

    protected function fakeWorkOS(): WorkOSFake
    {
        return WorkOS::fake();
    }

    protected function tearDownWorkOS(): void
    {
        WorkOS::restore();
    }
}
