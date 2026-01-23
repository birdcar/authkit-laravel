<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Tests;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Orchestra\Testbench\TestCase as Orchestra;
use WorkOS\AuthKit\Auth\WorkOSSession;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;
use WorkOS\AuthKit\WorkOSServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            WorkOSServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'WorkOS' => \WorkOS\AuthKit\Facades\WorkOS::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('workos.api_key', 'test_api_key');
        $app['config']->set('workos.client_id', 'test_client_id');
        $app['config']->set('workos.redirect_uri', 'http://localhost/auth/callback');
        // Use Laravel session-based session manager for tests
        $app['config']->set('workos.session.cookie_session', false);
    }

    protected function actingAsWorkOSUser(WorkOSSession $session): static
    {
        $user = new class extends Authenticatable
        {
            use HasWorkOSId;
            use HasWorkOSPermissions;

            protected $fillable = ['workos_id', 'email', 'name'];

            public $workos_id = 'user_123';

            public $email = 'test@example.com';

            public $name = 'Test User';
        };

        $user->setWorkOSSession($session);

        return $this->actingAs($user);
    }
}
