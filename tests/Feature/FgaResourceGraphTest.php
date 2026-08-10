<?php

declare(strict_types=1);

use Authkit\Authkit\Authorization\ResourceTarget;
use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Workbench\App\Models\Project;
use Workbench\App\Models\User;
use WorkOS\Resource\AuthorizationAssignment;
use WorkOS\Resource\AuthorizationResource;
use WorkOS\Resource\UserOrganizationMembershipBaseListData;

uses(UsesWorkosMockHandler::class)->group('depth-extensions');

// Test path: MockHandler — emulate's seed schema has no resources key and
// its authorization response shapes predate the v9.1 SDK (Phase 5 findings),
// so hierarchy and discovery cases assert exact request shapes instead.

/**
 * Workbench Project subclass whose parent hook returns a settable fixture —
 * the parent chain is wired in-memory, exactly how an app would return a
 * related model from workosParentResource().
 */
class FgaGraphProject extends Project
{
    protected $table = 'projects';

    public static ?Model $nextParent = null;

    public function workosParentResource(): ?Model
    {
        return static::$nextParent;
    }
}

beforeEach(function (): void {
    $this->migratePackageDatabase();
    FgaGraphProject::$nextParent = null;
});

/**
 * @return array<string, mixed>
 */
function fgaGraphResourceJson(string $externalId, ?string $parentResourceId = null): array
{
    return [
        'object' => 'authorization_resource',
        'id' => 'auth_res_'.$externalId,
        'name' => 'Project '.$externalId,
        'description' => null,
        'organization_id' => 'org_acme',
        'parent_resource_id' => $parentResourceId,
        'external_id' => $externalId,
        'resource_type_slug' => 'project',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ];
}

function fgaGraphJson(array $payload, int $status = 200): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($payload));
}

function fgaGraphMembershipListResponse(): Response
{
    return fgaGraphJson([
        'object' => 'list',
        'data' => [[
            'object' => 'organization_membership',
            'id' => 'om_01',
            'user_id' => 'user_01',
            'organization_id' => 'org_acme',
            'status' => 'active',
            'directory_managed' => false,
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
            'user' => [
                'id' => 'user_01',
                'email' => 'alice@acme.com',
                'email_verified' => true,
                'created_at' => '2026-01-01T00:00:00Z',
                'updated_at' => '2026-01-01T00:00:00Z',
            ],
        ]],
        'list_metadata' => ['before' => null, 'after' => null],
    ]);
}

describe('FgaResourceGraph', function (): void {
    it('creates a root resource with no parent when workosParentResource() is not overridden', function (): void {
        $this->fakeWorkosResponses([fgaGraphJson(fgaGraphResourceJson('1'), 201)]);

        Project::query()->create(['name' => 'Root', 'organization_id' => 'org_acme']);

        $body = json_decode((string) $this->workosRequestHistory[0]['request']->getBody(), true);

        expect($body)->not->toHaveKey('parent_resource_external_id')
            ->and($body)->not->toHaveKey('parent_resource_id')
            ->and($body['resource_type_slug'])->toBe('project');
    });

    it('sends the immediate parent as ParentResourceByExternalId at every level of a five-deep chain', function (): void {
        // WorkOS caps hierarchies at five levels (Dashboard-configured); a
        // full-depth chain proves the immediate-parent targeting holds at
        // every level, root through leaf.
        $this->fakeWorkosResponses(array_map(
            fn (int $i): Response => fgaGraphJson(fgaGraphResourceJson((string) $i), 201),
            range(1, 5),
        ));

        $previous = null;

        foreach (range(1, 5) as $depth) {
            FgaGraphProject::$nextParent = $previous;

            $previous = FgaGraphProject::query()->create([
                'name' => "Level {$depth}",
                'organization_id' => 'org_acme',
            ]);
        }

        foreach ($this->workosRequestHistory as $index => $entry) {
            $body = json_decode((string) $entry['request']->getBody(), true);

            if ($index === 0) {
                expect($body)->not->toHaveKey('parent_resource_external_id');

                continue;
            }

            // Each level nests under the level created just before it.
            expect($body['parent_resource_external_id'])->toBe(json_decode((string) $this->workosRequestHistory[$index - 1]['request']->getBody(), true)['external_id'])
                ->and($body['parent_resource_type_slug'])->toBe('project');
        }
    });

    it('rejects a parent model that is not a WorkOS resource before any HTTP call is made', function (): void {
        $this->fakeWorkosResponses([]);

        FgaGraphProject::$nextParent = User::query()->create([
            'name' => 'Not A Resource',
            'email' => 'nope@acme.com',
            'password' => 'secret',
        ]);

        expect(fn (): FgaGraphProject => FgaGraphProject::query()->create([
            'name' => 'Orphan',
            'organization_id' => 'org_acme',
        ]))->toThrow(InvalidArgumentException::class, 'workosParentResource() must return a model implementing');

        expect($this->workosRequestHistory)->toHaveCount(0);
    });

    it('busts the FGA cache after resource sync only when the cache is enabled', function (bool $cacheEnabled): void {
        config()->set('authkit.fga.cache.enabled', $cacheEnabled);

        $this->fakeWorkosResponses([
            fgaGraphJson(fgaGraphResourceJson('1'), 201),
            new Response(204),
        ]);

        $project = Project::query()->create(['name' => 'Synced', 'organization_id' => 'org_acme']);

        expect(Cache::get('authkit:fga:cache:generation'))->toBe($cacheEnabled ? 1 : null);

        $project->delete();

        expect(Cache::get('authkit:fga:cache:generation'))->toBe($cacheEnabled ? 2 : null);
    })->with([
        'cache enabled' => [true],
        'cache disabled' => [false],
    ]);

    it('lists resources a membership can access under a parent, for both targeting modes', function (ResourceTarget $parent, array $expectedQuery): void {
        $this->fakeWorkosResponses([fgaGraphJson([
            'object' => 'list',
            'data' => [fgaGraphResourceJson('proj_42', 'auth_res_root')],
            'list_metadata' => ['before' => null, 'after' => null],
        ])]);

        $page = Authkit::fga()->listResourcesForMembership('om_01', $parent, 'view', limit: 5);

        $request = $this->workosRequestHistory[0]['request'];
        parse_str($request->getUri()->getQuery(), $query);

        expect($page->data[0])->toBeInstanceOf(AuthorizationResource::class)
            ->and($request->getUri()->getPath())->toBe('/authorization/organization_memberships/om_01/resources')
            ->and($query)->toMatchArray($expectedQuery + ['permission_slug' => 'view', 'limit' => '5']);
    })->with([
        'by external id' => [
            ResourceTarget::byExternalId('folder_9', 'folder'),
            ['parent_resource_external_id' => 'folder_9', 'parent_resource_type_slug' => 'folder'],
        ],
        'by internal id' => [
            ResourceTarget::byId('auth_res_root'),
            ['parent_resource_id' => 'auth_res_root'],
        ],
    ]);

    it('lists memberships for a resource by internal id, with the assignment filter on the wire', function (): void {
        $this->fakeWorkosResponses([fgaGraphMembershipListResponse()]);

        $page = Authkit::fga()->listMembershipsForResource(
            'auth_res_1',
            'view',
            assignment: AuthorizationAssignment::Direct,
        );

        $request = $this->workosRequestHistory[0]['request'];
        parse_str($request->getUri()->getQuery(), $query);

        expect($page->data[0])->toBeInstanceOf(UserOrganizationMembershipBaseListData::class)
            ->and($request->getUri()->getPath())->toBe('/authorization/resources/auth_res_1/organization_memberships')
            ->and($query)->toMatchArray(['permission_slug' => 'view', 'assignment' => 'direct']);
    });

    it('lists memberships for a resource by external id, org, and type slug', function (): void {
        $this->fakeWorkosResponses([fgaGraphMembershipListResponse()]);

        $page = Authkit::fga()->listMembershipsForResourceByExternalId(
            'org_acme',
            'project',
            'proj_42',
            'edit',
        );

        $request = $this->workosRequestHistory[0]['request'];
        parse_str($request->getUri()->getQuery(), $query);

        expect($page->data)->toHaveCount(1)
            ->and($request->getUri()->getPath())->toBe('/authorization/organizations/org_acme/resources/project/proj_42/organization_memberships')
            ->and($query)->toMatchArray(['permission_slug' => 'edit'])
            ->and($query)->not->toHaveKey('assignment');
    });
});
