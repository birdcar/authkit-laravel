<?php

declare(strict_types=1);

use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use Authkit\Authkit\Tests\Fixtures\JwtFixture;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\Organization;
use Workbench\Database\Factories\UserFactory;

uses(UsesWorkosMockHandler::class);

beforeEach(function (): void {
    $this->migratePackageDatabase();
});

function currentOrgJwksResponse(): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(JwtFixture::jwks()));
}

it('resolves the same local org row through the facade and the request macro', function (): void {
    UserFactory::new()->create(['workos_id' => 'user_fixture']);
    $organization = Organization::query()->createQuietly(['name' => 'Acme', 'workos_id' => 'org_ctx']);

    Route::get('/current-org', function (Request $request): array {
        return [
            'macro' => $request->organization()?->getKey(),
            'facade' => Authkit::currentOrganization()?->getKey(),
        ];
    });

    $this->fakeWorkosResponses([currentOrgJwksResponse()]);

    $this->withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign(['org_id' => 'org_ctx'])))
        ->get('/current-org')
        ->assertOk()
        ->assertJson([
            'macro' => $organization->getKey(),
            'facade' => $organization->getKey(),
        ]);
});

it('memoizes resolution so repeated calls cost exactly one org query', function (): void {
    UserFactory::new()->create(['workos_id' => 'user_fixture']);
    Organization::query()->createQuietly(['name' => 'Acme', 'workos_id' => 'org_ctx']);

    Route::get('/current-org-twice', function (Request $request): array {
        $request->organization();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $request->organization();
        Authkit::currentOrganization();

        return ['queries_after_first_call' => $queries];
    });

    $this->fakeWorkosResponses([currentOrgJwksResponse()]);

    $this->withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign(['org_id' => 'org_ctx'])))
        ->get('/current-org-twice')
        ->assertOk()
        ->assertJson(['queries_after_first_call' => 0]);
});

it('returns null when the org_id claim has no matching local row', function (): void {
    UserFactory::new()->create(['workos_id' => 'user_fixture']);

    Route::get('/current-org', fn (Request $request): array => ['org' => $request->organization()?->getKey()]);

    $this->fakeWorkosResponses([currentOrgJwksResponse()]);

    $this->withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign(['org_id' => 'org_unknown'])))
        ->get('/current-org')
        ->assertOk()
        ->assertJson(['org' => null]);
});

it('returns null when the session carries no org_id claim at all', function (): void {
    UserFactory::new()->create(['workos_id' => 'user_fixture']);
    Organization::query()->createQuietly(['name' => 'Acme', 'workos_id' => 'org_ctx']);

    Route::get('/current-org', fn (Request $request): array => ['org' => $request->organization()?->getKey()]);

    $this->fakeWorkosResponses([currentOrgJwksResponse()]);

    $this->withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign()))
        ->get('/current-org')
        ->assertOk()
        ->assertJson(['org' => null]);
});

it('returns null for a guest request with no session cookie', function (): void {
    Route::get('/current-org', fn (Request $request): array => ['org' => $request->organization()?->getKey()]);

    $this->fakeWorkosResponses([]);

    $this->get('/current-org')
        ->assertOk()
        ->assertJson(['org' => null]);
});

it('returns null when no org model is configured even with an org_id claim', function (): void {
    config()->set('authkit.organization.model', null);

    UserFactory::new()->create(['workos_id' => 'user_fixture']);

    Route::get('/current-org', fn (Request $request): array => ['org' => $request->organization()?->getKey()]);

    $this->fakeWorkosResponses([currentOrgJwksResponse()]);

    $this->withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign(['org_id' => 'org_ctx'])))
        ->get('/current-org')
        ->assertOk()
        ->assertJson(['org' => null]);
});
