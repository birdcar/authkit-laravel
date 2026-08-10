<?php

declare(strict_types=1);

use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Models\WorkosMembership;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use Authkit\Authkit\Tests\Fixtures\JwtFixture;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\Organization;
use Workbench\App\Models\Project;
use Workbench\App\Models\SoftDeletableProject;
use Workbench\App\Policies\ProjectPolicy;
use Workbench\Database\Factories\UserFactory;
use WorkOS\Exception\UnprocessableEntityException;
use WorkOS\Resource\AuthorizationPermission;
use WorkOS\Resource\AuthorizationResource;
use WorkOS\Resource\Permission;

uses(UsesWorkosMockHandler::class);

// Test path: MockHandler — permission CRUD and resource CRUD are unconfirmed
// emulate gaps per the spec, and the policy path inherits FgaChecker's wire.

beforeEach(function (): void {
    $this->migratePackageDatabase();
});

/**
 * @return array<string, mixed>
 */
function authzMockPermissionJson(string $slug, string $name = '', bool $system = false): array
{
    return [
        'object' => 'permission',
        'id' => 'perm_'.$slug,
        'slug' => $slug,
        'name' => $name !== '' ? $name : ucfirst($slug),
        'description' => null,
        'system' => $system,
        'resource_type_slug' => 'project',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ];
}

function authzMockPermissionResponse(string $slug, string $name = ''): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(authzMockPermissionJson($slug, $name)));
}

function authzMockResourceResponse(string $externalId, string $name): Response
{
    return new Response(201, ['Content-Type' => 'application/json'], (string) json_encode([
        'object' => 'authorization_resource',
        'id' => 'auth_res_1',
        'name' => $name,
        'description' => null,
        'organization_id' => 'org_acme',
        'parent_resource_id' => null,
        'external_id' => $externalId,
        'resource_type_slug' => 'project',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]));
}

function authzMockJwksResponse(): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(JwtFixture::jwks()));
}

function authzMockCheckResponse(bool $authorized): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['authorized' => $authorized]));
}

function authzMockSeedProjectContext(): Project
{
    UserFactory::new()->create(['workos_id' => 'user_fixture']);

    WorkosMembership::query()->create([
        'workos_id' => 'om_alice',
        'organization_id' => 'org_acme',
        'user_id' => 'user_fixture',
        'role' => 'member',
        'status' => 'active',
    ]);

    return Project::query()->createQuietly([
        'name' => 'Skunkworks',
        'organization_id' => 'org_acme',
    ]);
}

it('round-trips the permission CRUD surface with the right request shapes', function (): void {
    $this->fakeWorkosResponses([
        authzMockPermissionResponse('posts.edit', 'Edit posts'),
        authzMockPermissionResponse('posts.edit'),
        authzMockPermissionResponse('posts.edit', 'Edit articles'),
        new Response(204),
    ]);

    $created = Authkit::permissions()->create('posts.edit', 'Edit posts');
    $fetched = Authkit::permissions()->get('posts.edit');
    $updated = Authkit::permissions()->update('posts.edit', name: 'Edit articles');
    Authkit::permissions()->delete('posts.edit');

    expect($created)->toBeInstanceOf(Permission::class)
        ->and($created->slug)->toBe('posts.edit')
        ->and($fetched)->toBeInstanceOf(AuthorizationPermission::class)
        ->and($updated->name)->toBe('Edit articles')
        ->and(array_map(
            fn (array $entry): string => $entry['request']->getMethod().' '.$entry['request']->getUri()->getPath(),
            $this->workosRequestHistory,
        ))->toBe([
            'POST /authorization/permissions',
            'GET /authorization/permissions/posts.edit',
            'PATCH /authorization/permissions/posts.edit',
            'DELETE /authorization/permissions/posts.edit',
        ])
        ->and(json_decode((string) $this->workosRequestHistory[0]['request']->getBody(), true))->toBe([
            'slug' => 'posts.edit',
            'name' => 'Edit posts',
        ]);
});

it('lists permissions as SDK resources', function (): void {
    $this->fakeWorkosResponses([
        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'object' => 'list',
            'data' => [authzMockPermissionJson('posts.edit'), authzMockPermissionJson('posts.view')],
            'list_metadata' => ['before' => null, 'after' => null],
        ])),
    ]);

    $permissions = Authkit::permissions()->all();

    expect($permissions)->toHaveCount(2)
        ->and($permissions[0])->toBeInstanceOf(AuthorizationPermission::class)
        ->and($permissions[0]->slug)->toBe('posts.edit');
});

it('surfaces the API rejection when deleting a system permission', function (): void {
    $this->fakeWorkosResponses([
        new Response(422, ['Content-Type' => 'application/json'], (string) json_encode([
            'message' => 'System permissions cannot be deleted.',
            'code' => 'system_permission',
            'error' => 'system_permission',
        ])),
    ]);

    expect(fn () => Authkit::permissions()->delete('sys.admin'))
        ->toThrow(UnprocessableEntityException::class);
});

it('creates an FGA resource with the right request shape', function (): void {
    $this->fakeWorkosResponses([authzMockResourceResponse('42', 'Project 42')]);

    $resource = Authkit::resources()->create(
        externalId: '42',
        name: 'Project 42',
        resourceTypeSlug: 'project',
        organizationId: 'org_acme',
    );

    $request = $this->workosRequestHistory[0]['request'];

    expect($resource)->toBeInstanceOf(AuthorizationResource::class)
        ->and($resource->externalId)->toBe('42')
        ->and($request->getMethod())->toBe('POST')
        ->and($request->getUri()->getPath())->toBe('/authorization/resources')
        ->and(json_decode((string) $request->getBody(), true))->toBe([
            'external_id' => '42',
            'name' => 'Project 42',
            'resource_type_slug' => 'project',
            'organization_id' => 'org_acme',
        ]);
});

it('deletes an FGA resource by external id, cascading only when asked', function (): void {
    $this->fakeWorkosResponses([new Response(204), new Response(204)]);

    Authkit::resources()->deleteByExternalId('org_acme', 'project', '42');
    Authkit::resources()->deleteByExternalId('org_acme', 'project', '42', cascadeDelete: true);

    $plain = $this->workosRequestHistory[0]['request'];
    $cascading = $this->workosRequestHistory[1]['request'];

    expect($plain->getMethod())->toBe('DELETE')
        ->and($plain->getUri()->getPath())->toBe('/authorization/organizations/org_acme/resources/project/42')
        ->and($plain->getUri()->getQuery())->toBe('')
        ->and($cascading->getUri()->getQuery())->toBe('cascade_delete=1');
});

it('syncs a created model into the FGA resource graph', function (): void {
    $this->fakeWorkosResponses([authzMockResourceResponse('1', 'Skunkworks')]);

    $project = Project::query()->create(['name' => 'Skunkworks', 'organization_id' => 'org_acme']);

    $request = $this->workosRequestHistory[0]['request'];

    expect($this->workosRequestHistory)->toHaveCount(1)
        ->and($request->getMethod())->toBe('POST')
        ->and($request->getUri()->getPath())->toBe('/authorization/resources')
        ->and(json_decode((string) $request->getBody(), true))->toBe([
            'external_id' => (string) $project->getKey(),
            'name' => 'Skunkworks',
            'resource_type_slug' => 'project',
            'organization_id' => 'org_acme',
        ]);
});

it('deletes the FGA resource when the model is deleted', function (): void {
    $project = Project::query()->createQuietly(['name' => 'Skunkworks', 'organization_id' => 'org_acme']);

    $this->fakeWorkosResponses([new Response(204)]);

    $project->delete();

    $request = $this->workosRequestHistory[0]['request'];

    expect($this->workosRequestHistory)->toHaveCount(1)
        ->and($request->getMethod())->toBe('DELETE')
        ->and($request->getUri()->getPath())
        ->toBe('/authorization/organizations/org_acme/resources/project/'.$project->getKey());
});

it('deletes the FGA resource on soft delete too, while the local row survives', function (): void {
    // Documents spec-phase-5 Failure Mode 8 rather than fixing it: Eloquent's
    // `deleted` event fires identically for soft deletes, so the remote FGA
    // resource is gone even though the local row is restorable.
    $project = SoftDeletableProject::query()->createQuietly(['name' => 'Skunkworks', 'organization_id' => 'org_acme']);

    $this->fakeWorkosResponses([new Response(204)]);

    $project->delete();

    expect($this->workosRequestHistory)->toHaveCount(1)
        ->and($this->workosRequestHistory[0]['request']->getMethod())->toBe('DELETE')
        ->and(SoftDeletableProject::withTrashed()->whereKey($project->getKey())->exists())->toBeTrue()
        ->and(SoftDeletableProject::query()->whereKey($project->getKey())->exists())->toBeFalse();
});

it('authorizes policy abilities through the FGA check', function (bool $authorized): void {
    $project = authzMockSeedProjectContext();

    Route::get('/authz-mock-can-view/{id}', function (string $id): array {
        $project = Project::query()->findOrFail($id);

        return ['can' => auth('workos')->user()?->can('view', $project)];
    });

    $this->fakeWorkosResponses([authzMockJwksResponse(), authzMockCheckResponse($authorized)]);

    $this->withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign(['org_id' => 'org_acme'])))
        ->get('/authz-mock-can-view/'.$project->getKey())
        ->assertOk()
        ->assertJson(['can' => $authorized]);

    $check = $this->workosRequestHistory[1]['request'];

    expect($check->getUri()->getPath())->toBe('/authorization/organization_memberships/om_alice/check')
        ->and(json_decode((string) $check->getBody(), true))->toBe([
            'permission_slug' => 'view',
            'resource_external_id' => (string) $project->getKey(),
            'resource_type_slug' => 'project',
        ]);
})->with(['authorized' => [true], 'denied' => [false]]);

it('denies class-string abilities without attempting any FGA check', function (): void {
    authzMockSeedProjectContext();

    Route::get('/authz-mock-can-create', fn (): array => [
        'can' => auth('workos')->user()?->can('create', Project::class),
    ]);

    $this->fakeWorkosResponses([authzMockJwksResponse()]);

    $this->withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign(['org_id' => 'org_acme'])))
        ->get('/authz-mock-can-create')
        ->assertOk()
        ->assertJson(['can' => false]);

    // Exactly one request — the guard's JWKS fetch. No resource instance
    // means no check is even attempted (spec-phase-5 Failure Mode 7).
    expect($this->workosRequestHistory)->toHaveCount(1);
});

it('rejects models that are not WorkOS resources with a LogicException', function (): void {
    Gate::policy(Organization::class, ProjectPolicy::class);

    $user = UserFactory::new()->create(['workos_id' => 'user_fixture']);
    $organization = Organization::query()->createQuietly(['name' => 'Acme', 'workos_id' => 'org_acme']);

    // Empty queue: reaching the FGA check instead of throwing would fail as
    // "Mock queue is empty" rather than passing silently.
    $this->fakeWorkosResponses([]);

    expect(fn (): bool => Gate::forUser($user)->check('view', $organization))
        ->toThrow(LogicException::class, 'must implement');
});
