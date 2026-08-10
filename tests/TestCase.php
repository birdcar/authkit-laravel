<?php

declare(strict_types=1);

namespace Authkit\Authkit\Tests;

use Authkit\Authkit\AuthkitServiceProvider;
use Authkit\Authkit\Tests\Fixtures\JwtFixture;
use Orchestra\Testbench\TestCase as Orchestra;
use Workbench\App\Models\Organization;
use Workbench\App\Models\User;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            AuthkitServiceProvider::class,
        ];
    }

    /**
     * Skeleton `users` table plus this package's own migrations.
     *
     * The package path is migrated explicitly rather than via a bare `migrate`,
     * which would also sweep the skeleton's database/migrations — where
     * InstallIdempotentTest's `authkit:install` briefly leaves a published copy of
     * the same migration. Under --parallel that copy is visible to other workers,
     * and applying add_workos_id twice fails with "duplicate column name".
     */
    protected function migratePackageDatabase(): void
    {
        $this->loadLaravelMigrations();

        $this->artisan('migrate', [
            '--path' => realpath(__DIR__.'/../database/migrations'),
            '--realpath' => true,
        ])->run();

        // The workbench `organizations` fixture table backs every suite that
        // exercises HasWorkosOrganization against a real Eloquent model.
        $this->artisan('migrate', [
            '--path' => realpath(__DIR__.'/../workbench/database/migrations'),
            '--realpath' => true,
        ])->run();
    }

    /**
     * Package suites run against Testbench's synthetic app, not the workbench app,
     * so the guard/provider entries a consumer would put in config/auth.php have to
     * be set here independently of what WorkbenchServiceProvider does.
     */
    protected function getEnvironmentSetUp($app): void
    {
        // The skeleton's .env (and its APP_KEY) only exists after a workbench
        // build and is purged on every composer install, so routes running the
        // `web` group (EncryptCookies needs the encrypter) would fail on a fresh
        // checkout. Pinned here to keep the suite hermetic.
        $app['config']->set('app.key', 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF');

        // Non-empty on purpose: SessionManager::refresh() calls
        // HttpClient::requireApiKey(), which throws on an empty key — and refresh()
        // swallows that into a generic failure before any HTTP call is made.
        $app['config']->set('authkit.api_key', 'sk_test_fixture');
        $app['config']->set('authkit.client_id', JwtFixture::CLIENT_ID);
        $app['config']->set('authkit.cookie_password', JwtFixture::COOKIE_PASSWORD);
        $app['config']->set('authkit.base_url', 'https://api.workos.com');
        $app['config']->set('authkit.jwt.issuer', JwtFixture::ISSUER);
        $app['config']->set('authkit.jwt.audience', JwtFixture::CLIENT_ID);

        $app['config']->set('auth.guards.workos', [
            'driver' => 'workos',
            'provider' => 'workos',
        ]);
        $app['config']->set('auth.providers.workos', [
            'driver' => 'eloquent',
            'model' => User::class,
        ]);

        // Package suites run without WorkbenchServiceProvider, so the org model
        // the workbench would configure is set here independently.
        $app['config']->set('authkit.organization.model', Organization::class);

        // Sync queue keeps observer-dispatched jobs observable in-request; the
        // suites that need real dequeue mechanics switch to `database` locally.
        $app['config']->set('queue.default', 'sync');

        // ArrayStore implements LockProvider, which is what SessionRefresher's
        // single-flight lock needs; a file-store default would make the lock
        // behavior depend on the machine running the suite.
        $app['config']->set('cache.default', 'array');

        // Not the default `cookie` handler: CookieSessionHandler reads the request
        // off a property StartSession sets, so seeding a session outside a request
        // (withSession()) fatals on it.
        $app['config']->set('session.driver', 'array');

        $app['config']->set('database.default', 'testing');
    }
}
