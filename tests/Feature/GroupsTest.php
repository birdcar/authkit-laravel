<?php

declare(strict_types=1);

use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use WorkOS\Resource\Group;
use WorkOS\Resource\GroupRoleAssignment;
use WorkOS\Resource\ReplaceGroupRoleAssignmentEntry;
use WorkOS\Resource\UserOrganizationMembershipBaseListData;

uses(UsesWorkosMockHandler::class)->group('depth-extensions');

// Test path: MockHandler — emulate 0.6.0 ships no group endpoints at all
// (its authorization coverage notes say so explicitly), so every case here
// asserts the exact SDK call and argument shape against request history.

/**
 * @return array<string, mixed>
 */
function groupJson(string $id = 'grp_01', string $name = 'Engineering'): array
{
    return [
        'object' => 'group',
        'id' => $id,
        'organization_id' => 'org_acme',
        'name' => $name,
        'description' => null,
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ];
}

/**
 * @return array<string, mixed>
 */
function groupRoleAssignmentJson(string $id = 'gra_01'): array
{
    return [
        'object' => 'group_role_assignment',
        'id' => $id,
        'group_id' => 'grp_01',
        'role' => ['slug' => 'editor'],
        'resource' => ['id' => 'res_01', 'external_id' => 'proj_42', 'resource_type_slug' => 'project'],
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ];
}

function groupsJsonResponse(array $payload, int $status = 200): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($payload));
}

function groupsListResponse(array $items): Response
{
    return groupsJsonResponse([
        'object' => 'list',
        'data' => $items,
        'list_metadata' => ['before' => null, 'after' => null],
    ]);
}

describe('Groups', function (): void {
    it('walks the full org-groups CRUD, sending each exact SDK call', function (): void {
        $this->fakeWorkosResponses([
            groupsListResponse([groupJson()]),
            groupsJsonResponse(groupJson('grp_02', 'Support'), 201),
            groupsJsonResponse(groupJson('grp_02', 'Support')),
            groupsJsonResponse(groupJson('grp_02', 'Customer Support')),
            new Response(204),
        ]);

        $page = Authkit::groups()->list('org_acme', limit: 10);
        expect($page->data)->toHaveCount(1)
            ->and($page->data[0])->toBeInstanceOf(Group::class)
            ->and($page->data[0]->name)->toBe('Engineering');

        $created = Authkit::groups()->create('org_acme', 'Support', 'Handles tickets');
        expect($created->id)->toBe('grp_02');

        expect(Authkit::groups()->get('org_acme', 'grp_02')->name)->toBe('Support');

        $updated = Authkit::groups()->update('org_acme', 'grp_02', name: 'Customer Support');
        expect($updated->name)->toBe('Customer Support');

        Authkit::groups()->delete('org_acme', 'grp_02');

        $paths = array_map(
            fn (array $entry): string => $entry['request']->getMethod().' '.$entry['request']->getUri()->getPath(),
            $this->workosRequestHistory,
        );

        expect($paths)->toBe([
            'GET /organizations/org_acme/groups',
            'POST /organizations/org_acme/groups',
            'GET /organizations/org_acme/groups/grp_02',
            'PATCH /organizations/org_acme/groups/grp_02',
            'DELETE /organizations/org_acme/groups/grp_02',
        ])
            ->and($this->workosRequestHistory[0]['request']->getUri()->getQuery())->toContain('limit=10')
            ->and(json_decode((string) $this->workosRequestHistory[1]['request']->getBody(), true))
            ->toBe(['name' => 'Support', 'description' => 'Handles tickets'])
            ->and(json_decode((string) $this->workosRequestHistory[3]['request']->getBody(), true))
            ->toBe(['name' => 'Customer Support']);
    });

    it('manages group membership: list, add, and remove members', function (): void {
        $this->fakeWorkosResponses([
            groupsListResponse([[
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
            ]]),
            groupsJsonResponse(groupJson(), 201),
            new Response(204),
        ]);

        $members = Authkit::groups()->members('org_acme', 'grp_01');
        expect($members->data)->toHaveCount(1)
            ->and($members->data[0])->toBeInstanceOf(UserOrganizationMembershipBaseListData::class)
            ->and($members->data[0]->id)->toBe('om_01');

        Authkit::groups()->addMember('org_acme', 'grp_01', 'om_02');
        Authkit::groups()->removeMember('org_acme', 'grp_01', 'om_02');

        $paths = array_map(
            fn (array $entry): string => $entry['request']->getMethod().' '.$entry['request']->getUri()->getPath(),
            $this->workosRequestHistory,
        );

        expect($paths)->toBe([
            'GET /organizations/org_acme/groups/grp_01/organization-memberships',
            'POST /organizations/org_acme/groups/grp_01/organization-memberships',
            'DELETE /organizations/org_acme/groups/grp_01/organization-memberships/om_02',
        ])
            ->and(json_decode((string) $this->workosRequestHistory[1]['request']->getBody(), true))
            ->toBe(['organization_membership_id' => 'om_02']);
    });

    it('lists the groups an organization membership belongs to', function (): void {
        $this->fakeWorkosResponses([groupsListResponse([groupJson()])]);

        $groups = Authkit::groups()->forMembership('om_01');

        $request = $this->workosRequestHistory[0]['request'];

        expect($groups->data)->toHaveCount(1)
            ->and($groups->data[0])->toBeInstanceOf(Group::class)
            ->and($request->getMethod())->toBe('GET')
            ->and($request->getUri()->getPath())->toBe('/user_management/organization_memberships/om_01/groups');
    });

    it('reads group role assignments: list and get-by-id', function (): void {
        $this->fakeWorkosResponses([
            groupsListResponse([groupRoleAssignmentJson()]),
            groupsJsonResponse(groupRoleAssignmentJson()),
        ]);

        $assignments = Authkit::groups()->roleAssignments('grp_01');
        expect($assignments->data)->toHaveCount(1)
            ->and($assignments->data[0])->toBeInstanceOf(GroupRoleAssignment::class)
            ->and($assignments->data[0]->role->slug)->toBe('editor');

        $single = Authkit::groups()->roleAssignment('grp_01', 'gra_01');
        expect($single->id)->toBe('gra_01');

        $paths = array_map(
            fn (array $entry): string => $entry['request']->getMethod().' '.$entry['request']->getUri()->getPath(),
            $this->workosRequestHistory,
        );

        expect($paths)->toBe([
            'GET /authorization/groups/grp_01/role_assignments',
            'GET /authorization/groups/grp_01/role_assignments/gra_01',
        ]);
    });

    it('sends the exact assignment mutation shapes on the wire', function (): void {
        $this->fakeWorkosResponses([
            groupsJsonResponse(groupRoleAssignmentJson(), 201),
            groupsJsonResponse([
                'object' => 'list',
                'data' => [groupRoleAssignmentJson()],
                'list_metadata' => ['before' => null, 'after' => null],
            ]),
            new Response(204),
            new Response(204),
        ]);

        Authkit::groups()->assignRole('grp_01', 'editor', resourceExternalId: 'proj_42', resourceTypeSlug: 'project');
        Authkit::groups()->replaceRoleAssignments('grp_01', [
            new ReplaceGroupRoleAssignmentEntry(roleSlug: 'viewer', resourceId: 'res_01'),
        ]);
        Authkit::groups()->removeRoleAssignmentsByCriteria('grp_01', 'editor', resourceId: 'res_01');
        Authkit::groups()->removeRoleAssignment('grp_01', 'gra_01');

        $requests = array_map(fn (array $entry) => $entry['request'], $this->workosRequestHistory);

        expect($requests[0]->getMethod())->toBe('POST')
            ->and($requests[0]->getUri()->getPath())->toBe('/authorization/groups/grp_01/role_assignments')
            ->and(json_decode((string) $requests[0]->getBody(), true))->toBe([
                'role_slug' => 'editor',
                'resource_external_id' => 'proj_42',
                'resource_type_slug' => 'project',
            ])
            ->and($requests[1]->getMethod())->toBe('PUT')
            ->and(json_decode((string) $requests[1]->getBody(), true))->toBe([
                'role_assignments' => [[
                    'role_slug' => 'viewer',
                    'resource_id' => 'res_01',
                    'resource_external_id' => null,
                    'resource_type_slug' => null,
                ]],
            ])
            ->and($requests[2]->getMethod())->toBe('DELETE')
            ->and($requests[2]->getUri()->getPath())->toBe('/authorization/groups/grp_01/role_assignments')
            ->and(json_decode((string) $requests[2]->getBody(), true))->toBe([
                'role_slug' => 'editor',
                'resource_id' => 'res_01',
            ])
            ->and($requests[3]->getMethod())->toBe('DELETE')
            ->and($requests[3]->getUri()->getPath())->toBe('/authorization/groups/grp_01/role_assignments/gra_01');
    });

    it('bumps the FGA cache generation on each role-assignment mutation when the cache is enabled, and leaves the store untouched when disabled', function (string $method, bool $cacheEnabled): void {
        config()->set('authkit.fga.cache.enabled', $cacheEnabled);

        $responses = match ($method) {
            'assignRole' => [groupsJsonResponse(groupRoleAssignmentJson(), 201)],
            'replaceRoleAssignments' => [groupsJsonResponse([
                'object' => 'list',
                'data' => [],
                'list_metadata' => ['before' => null, 'after' => null],
            ])],
            default => [new Response(204)],
        };

        $this->fakeWorkosResponses($responses);

        match ($method) {
            'assignRole' => Authkit::groups()->assignRole('grp_01', 'editor', resourceId: 'res_01'),
            'replaceRoleAssignments' => Authkit::groups()->replaceRoleAssignments('grp_01', []),
            'removeRoleAssignmentsByCriteria' => Authkit::groups()->removeRoleAssignmentsByCriteria('grp_01', 'editor'),
            'removeRoleAssignment' => Authkit::groups()->removeRoleAssignment('grp_01', 'gra_01'),
        };

        expect(Cache::get('authkit:fga:cache:generation'))->toBe($cacheEnabled ? 1 : null);
    })->with([
        'assignRole',
        'replaceRoleAssignments',
        'removeRoleAssignmentsByCriteria',
        'removeRoleAssignment',
    ])->with([
        'cache enabled' => [true],
        'cache disabled' => [false],
    ]);

    it('does not bust the cache on pure reads or non-assignment group writes', function (): void {
        config()->set('authkit.fga.cache.enabled', true);

        $this->fakeWorkosResponses([
            groupsListResponse([groupJson()]),
            groupsJsonResponse(groupJson(), 201),
            new Response(204),
        ]);

        Authkit::groups()->list('org_acme');
        Authkit::groups()->create('org_acme', 'Engineering');
        Authkit::groups()->delete('org_acme', 'grp_01');

        expect(Cache::get('authkit:fga:cache:generation'))->toBeNull();
    });
});
