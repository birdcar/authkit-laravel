<?php

declare(strict_types=1);

use Authkit\Authkit\Authorization\NullMembershipResolver;
use Authkit\Authkit\Authorization\ResourceTarget;
use Authkit\Authkit\Authorization\RoleManager;
use Authkit\Authkit\Contracts\ResolvesOrganizationMembershipId;
use Authkit\Authkit\Contracts\WorkosClientManager as WorkosClientManagerContract;
use Authkit\Authkit\Exceptions\MembershipNotResolvedException;
use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Models\WorkosMembership;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use Authkit\Authkit\Tests\Fixtures\JwtFixture;
use Authkit\Authkit\Tests\Support\EmulateServer;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Route;
use Workbench\Database\Factories\UserFactory;
use WorkOS\PaginatedResponse;
use WorkOS\Resource\Role;
use WorkOS\Resource\UserRoleAssignment;
use WorkOS\Service\Authorization;

uses(UsesWorkosMockHandler::class);

// Test path: MockHandler. The spec's default path for RoleManager CRUD and
// FgaChecker::check() was emulate, but @workos/emulate@0.6.0 has drifted from
// SDK v9.1's Authorization surface (its check endpoint expects `permission`
// where the SDK sends `permission_slug`, assignRole expects `role_id` where
// the SDK sends `role_slug`, and its role responses omit resource_type_slug
// and permissions, which Role::fromArray requires) — verified empirically, so
// the spec's §4.4/§8 fallbacks downgrade these cases to MockHandler. One
// emulate smoke test at the bottom keeps real-wire fidelity for the list
// endpoints whose (empty) responses the SDK can parse.

beforeEach(function (): void {
    $this->migratePackageDatabase();
});

afterEach(function (): void {
    if (isset($this->server)) {
        $this->server->stop();
    }
});

function authzJwksResponse(): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(JwtFixture::jwks()));
}

function authzCheckResponse(bool $authorized): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['authorized' => $authorized]));
}

/**
 * @return array<string, mixed>
 */
function authzRoleJson(string $slug, string $type = 'OrganizationRole', string $name = ''): array
{
    return [
        'object' => 'role',
        'id' => 'role_'.$slug,
        'slug' => $slug,
        'name' => $name !== '' ? $name : ucfirst($slug),
        'description' => null,
        'type' => $type,
        'resource_type_slug' => 'project',
        'permissions' => ['posts.edit'],
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ];
}

function authzRoleResponse(string $slug, string $type = 'OrganizationRole', string $name = ''): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(authzRoleJson($slug, $type, $name)));
}

/**
 * @return array<string, mixed>
 */
function authzAssignmentJson(string $id = 'ra_1'): array
{
    return [
        'object' => 'role_assignment',
        'id' => $id,
        'organization_membership_id' => 'om_alice',
        'role' => ['slug' => 'org-editor'],
        'resource' => ['id' => 'auth_res_1', 'external_id' => '42', 'resource_type_slug' => 'project'],
        'source' => ['type' => 'direct'],
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $data
 */
function authzListResponse(array $data): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'object' => 'list',
        'data' => $data,
        'list_metadata' => ['before' => null, 'after' => null],
    ]));
}

it('creates an organization role with the right request shape', function (): void {
    $this->fakeWorkosResponses([authzRoleResponse('org-editor', name: 'Org Editor')]);

    $role = Authkit::roles()->createOrganizationRole('org_acme', 'Org Editor', 'org-editor');

    $request = $this->workosRequestHistory[0]['request'];

    expect($role)->toBeInstanceOf(Role::class)
        ->and($role->slug)->toBe('org-editor')
        ->and($request->getMethod())->toBe('POST')
        ->and($request->getUri()->getPath())->toBe('/authorization/organizations/org_acme/roles')
        ->and(json_decode((string) $request->getBody(), true))->toBe([
            'slug' => 'org-editor',
            'name' => 'Org Editor',
        ]);
});

it('round-trips the organization role lifecycle: list, get, update, delete', function (): void {
    $this->fakeWorkosResponses([
        authzListResponse([authzRoleJson('org-editor')]),
        authzRoleResponse('org-editor'),
        authzRoleResponse('org-editor', name: 'Editor v2'),
        new Response(204),
    ]);

    $roles = Authkit::roles()->forOrganization('org_acme');
    Authkit::roles()->getOrganizationRole('org_acme', 'org-editor');
    Authkit::roles()->updateOrganizationRole('org_acme', 'org-editor', name: 'Editor v2');
    Authkit::roles()->deleteOrganizationRole('org_acme', 'org-editor');

    expect($roles)->toHaveCount(1)
        ->and($roles[0])->toBeInstanceOf(Role::class)
        ->and(array_map(
            fn (array $entry): string => $entry['request']->getMethod().' '.$entry['request']->getUri()->getPath(),
            $this->workosRequestHistory,
        ))->toBe([
            'GET /authorization/organizations/org_acme/roles',
            'GET /authorization/organizations/org_acme/roles/org-editor',
            'PATCH /authorization/organizations/org_acme/roles/org-editor',
            'DELETE /authorization/organizations/org_acme/roles/org-editor',
        ]);
});

it('manages organization role permissions with the right request shapes', function (): void {
    $this->fakeWorkosResponses([
        authzRoleResponse('org-editor'),
        authzRoleResponse('org-editor'),
        new Response(204),
    ]);

    Authkit::roles()->addOrganizationRolePermission('org_acme', 'org-editor', 'posts.edit');
    Authkit::roles()->setOrganizationRolePermissions('org_acme', 'org-editor', ['posts.edit', 'posts.view']);
    Authkit::roles()->removeOrganizationRolePermission('org_acme', 'org-editor', 'posts.edit');

    expect($this->workosRequestHistory[0]['request']->getMethod())->toBe('POST')
        ->and(json_decode((string) $this->workosRequestHistory[0]['request']->getBody(), true))->toBe(['slug' => 'posts.edit'])
        ->and($this->workosRequestHistory[1]['request']->getMethod())->toBe('PUT')
        ->and(json_decode((string) $this->workosRequestHistory[1]['request']->getBody(), true))->toBe(['permissions' => ['posts.edit', 'posts.view']])
        ->and($this->workosRequestHistory[2]['request']->getMethod())->toBe('DELETE')
        ->and($this->workosRequestHistory[2]['request']->getUri()->getPath())
        ->toBe('/authorization/organizations/org_acme/roles/org-editor/permissions/posts.edit');
});

it('round-trips the environment role surface', function (): void {
    $this->fakeWorkosResponses([
        authzRoleResponse('env-auditor', 'EnvironmentRole', 'Env Auditor'),
        authzRoleResponse('env-auditor', 'EnvironmentRole'),
        authzRoleResponse('env-auditor', 'EnvironmentRole', 'Auditor v2'),
        authzRoleResponse('env-auditor', 'EnvironmentRole'),
        authzRoleResponse('env-auditor', 'EnvironmentRole'),
        authzListResponse([authzRoleJson('env-auditor', 'EnvironmentRole')]),
    ]);

    $created = Authkit::roles()->createEnvironmentRole('env-auditor', 'Env Auditor');
    Authkit::roles()->getEnvironmentRole('env-auditor');
    Authkit::roles()->updateEnvironmentRole('env-auditor', name: 'Auditor v2');
    Authkit::roles()->addEnvironmentRolePermission('env-auditor', 'posts.edit');
    Authkit::roles()->setEnvironmentRolePermissions('env-auditor', ['posts.edit']);
    $all = Authkit::roles()->environment();

    expect($created->slug)->toBe('env-auditor')
        ->and($all)->toHaveCount(1)
        ->and(array_map(
            fn (array $entry): string => $entry['request']->getMethod().' '.$entry['request']->getUri()->getPath(),
            $this->workosRequestHistory,
        ))->toBe([
            'POST /authorization/roles',
            'GET /authorization/roles/env-auditor',
            'PATCH /authorization/roles/env-auditor',
            'POST /authorization/roles/env-auditor/permissions',
            'PUT /authorization/roles/env-auditor/permissions',
            'GET /authorization/roles',
        ]);
});

it('offers no environment role delete because the SDK and API have none', function (): void {
    // If a future SDK bump adds deleteEnvironmentRole, this fails on purpose:
    // RoleManager's omission is then a gap to close, not a fact to preserve.
    expect(method_exists(Authorization::class, 'deleteEnvironmentRole'))->toBeFalse()
        ->and(method_exists(RoleManager::class, 'deleteEnvironmentRole'))->toBeFalse();
});

it('assigns, lists, and removes resource-scoped roles for a membership', function (): void {
    $this->fakeWorkosResponses([
        new Response(201, ['Content-Type' => 'application/json'], (string) json_encode(authzAssignmentJson())),
        authzListResponse([authzAssignmentJson()]),
        new Response(204),
        authzListResponse([]),
    ]);

    $assignment = Authkit::roles()->assign('om_alice', 'org-editor', ResourceTarget::byExternalId('42', 'project'));
    $listed = Authkit::roles()->assignmentsFor('om_alice');
    Authkit::roles()->remove('om_alice', 'org-editor', ResourceTarget::byExternalId('42', 'project'));
    $afterRemoval = Authkit::roles()->assignmentsFor('om_alice');

    $assign = $this->workosRequestHistory[0]['request'];
    $remove = $this->workosRequestHistory[2]['request'];

    expect($assignment)->toBeInstanceOf(UserRoleAssignment::class)
        ->and($assignment->role->slug)->toBe('org-editor')
        ->and($listed)->toBeInstanceOf(PaginatedResponse::class)
        ->and($listed->data)->toHaveCount(1)
        ->and($afterRemoval->data)->toBeEmpty()
        ->and($assign->getMethod())->toBe('POST')
        ->and($assign->getUri()->getPath())->toBe('/authorization/organization_memberships/om_alice/role_assignments')
        ->and(json_decode((string) $assign->getBody(), true))->toBe([
            'role_slug' => 'org-editor',
            'resource_external_id' => '42',
            'resource_type_slug' => 'project',
        ])
        ->and($remove->getMethod())->toBe('DELETE')
        ->and(json_decode((string) $remove->getBody(), true))->toBe([
            'role_slug' => 'org-editor',
            'resource_external_id' => '42',
            'resource_type_slug' => 'project',
        ]);
});

it('targets a resource by internal id when asked', function (): void {
    $this->fakeWorkosResponses([
        new Response(201, ['Content-Type' => 'application/json'], (string) json_encode(authzAssignmentJson())),
    ]);

    Authkit::roles()->assign('om_alice', 'org-editor', ResourceTarget::byId('auth_res_1'));

    expect(json_decode((string) $this->workosRequestHistory[0]['request']->getBody(), true))->toBe([
        'role_slug' => 'org-editor',
        'resource_id' => 'auth_res_1',
    ]);
});

it('removes a role assignment by id', function (): void {
    $this->fakeWorkosResponses([new Response(204)]);

    Authkit::roles()->removeAssignment('om_alice', 'ra_1');

    expect($this->workosRequestHistory[0]['request']->getMethod())->toBe('DELETE')
        ->and($this->workosRequestHistory[0]['request']->getUri()->getPath())
        ->toBe('/authorization/organization_memberships/om_alice/role_assignments/ra_1');
});

it('checks a permission on a resource and maps both check outcomes', function (bool $authorized): void {
    $this->fakeWorkosResponses([authzCheckResponse($authorized)]);

    $result = Authkit::check('posts.edit', '42', 'project', organizationMembershipId: 'om_alice');

    $request = $this->workosRequestHistory[0]['request'];

    expect($result)->toBe($authorized)
        ->and($request->getMethod())->toBe('POST')
        ->and($request->getUri()->getPath())->toBe('/authorization/organization_memberships/om_alice/check')
        ->and(json_decode((string) $request->getBody(), true))->toBe([
            'permission_slug' => 'posts.edit',
            'resource_external_id' => '42',
            'resource_type_slug' => 'project',
        ]);
})->with(['authorized' => [true], 'denied' => [false]]);

it('throws MembershipNotResolvedException when nothing can resolve a membership', function (): void {
    $this->app->instance(ResolvesOrganizationMembershipId::class, new NullMembershipResolver);

    // Empty queue: if the checker attempted any HTTP call before failing
    // loudly, Guzzle would throw "Mock queue is empty" instead.
    $this->fakeWorkosResponses([]);

    expect(fn (): bool => Authkit::check('posts.edit', '42', 'project'))
        ->toThrow(MembershipNotResolvedException::class);

    expect($this->workosRequestHistory)->toHaveCount(0);
});

it('resolves the membership from the session claims and the projection', function (): void {
    UserFactory::new()->create(['workos_id' => 'user_fixture']);
    WorkosMembership::query()->create([
        'workos_id' => 'om_alice',
        'organization_id' => 'org_acme',
        'user_id' => 'user_fixture',
        'role' => 'member',
        'status' => 'active',
    ]);

    Route::get('/authz-implicit-check', fn (): array => [
        'allowed' => Authkit::check('posts.edit', '42', 'project'),
    ]);

    $this->fakeWorkosResponses([authzJwksResponse(), authzCheckResponse(true)]);

    $this->withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign(['org_id' => 'org_acme'])))
        ->get('/authz-implicit-check')
        ->assertOk()
        ->assertJson(['allowed' => true]);

    expect($this->workosRequestHistory)->toHaveCount(2)
        ->and($this->workosRequestHistory[1]['request']->getUri()->getPath())
        ->toBe('/authorization/organization_memberships/om_alice/check');
});

it('grants abilities from permissions claims through the real guard with zero HTTP per check', function (): void {
    UserFactory::new()->create(['workos_id' => 'user_fixture']);

    Route::get('/authz-gate-check', function (): array {
        $user = auth('workos')->user();

        return [
            'granted' => $user?->can('posts.edit'),
            'denied' => $user?->can('posts.delete'),
        ];
    });

    $this->fakeWorkosResponses([authzJwksResponse()]);

    $this->withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign(['permissions' => ['posts.edit']])))
        ->get('/authz-gate-check')
        ->assertOk()
        ->assertJson(['granted' => true, 'denied' => false]);

    // Exactly one request — the guard's JWKS fetch. The two Gate checks above
    // cost zero HTTP: a claims mismatch falls through to the Gate's default
    // deny rather than issuing any WorkOS call.
    expect($this->workosRequestHistory)->toHaveCount(1);
});

it('grants abilities from roles claims through the real guard', function (): void {
    UserFactory::new()->create(['workos_id' => 'user_fixture']);

    Route::get('/authz-gate-role-check', fn (): array => [
        'granted' => auth('workos')->user()?->can('admin'),
    ]);

    $this->fakeWorkosResponses([authzJwksResponse()]);

    $this->withUnencryptedCookie('authkit_session', JwtFixture::sealedCookie(JwtFixture::sign(['roles' => ['admin']])))
        ->get('/authz-gate-role-check')
        ->assertOk()
        ->assertJson(['granted' => true]);
});

it('round-trips the environment role and permission lists against a running emulator', function (): void {
    $this->server = new EmulateServer(port: 4196);
    $this->server->start();

    config()->set('authkit.emulate.enabled', true);
    config()->set('authkit.emulate.base_url', $this->server->baseUrl());
    app()->forgetInstance(WorkosClientManagerContract::class);

    // Real wire, real server: proves path/auth/base-url fidelity for the
    // Authorization service. Both lists are empty because nothing is seeded —
    // deliberately: emulate 0.6.0's non-empty role/permission payloads use a
    // legacy shape SDK v9.1 cannot parse (see the top-of-file note).
    expect(Authkit::roles()->environment())->toBe([])
        ->and(Authkit::permissions()->all())->toBe([]);
})->skip(fn (): bool => ! EmulateServer::isAvailable(), 'npx/node not available');
