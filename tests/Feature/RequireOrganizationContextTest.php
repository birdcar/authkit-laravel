<?php

declare(strict_types=1);

use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use Authkit\Authkit\Tests\Fixtures\JwtFixture;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\Organization;
use Workbench\Database\Factories\UserFactory;

uses(UsesWorkosMockHandler::class);

beforeEach(function (): void {
    $this->migratePackageDatabase();

    Route::get('/needs-org', fn (): string => 'reached')->middleware('authkit.org');
    Route::get('/pick-an-org', fn (): string => 'picker')->name('organizations.pick');
});

it('passes the request through when a current org resolves', function (): void {
    UserFactory::new()->create(['workos_id' => 'user_fixture']);
    Organization::query()->createQuietly(['name' => 'Acme', 'workos_id' => 'org_ctx']);

    $this->fakeWorkosResponses([
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(JwtFixture::jwks())),
    ]);

    $this->withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign(['org_id' => 'org_ctx'])))
        ->get('/needs-org')
        ->assertOk()
        ->assertSee('reached');
});

it('aborts with 403 by default when no current org resolves', function (): void {
    $this->fakeWorkosResponses([]);

    $this->get('/needs-org')->assertForbidden();
});

it('redirects to the configured route when on_missing is redirect', function (): void {
    config()->set('authkit.organization.middleware.on_missing', 'redirect');
    config()->set('authkit.organization.middleware.redirect_route', 'organizations.pick');

    $this->fakeWorkosResponses([]);

    $this->get('/needs-org')->assertRedirect(route('organizations.pick'));
});

it('fails loudly with 500 naming the config key when redirect_route is unset', function (): void {
    config()->set('authkit.organization.middleware.on_missing', 'redirect');
    config()->set('authkit.organization.middleware.redirect_route', null);

    $this->fakeWorkosResponses([]);

    $this->get('/needs-org')->assertStatus(500);
});
