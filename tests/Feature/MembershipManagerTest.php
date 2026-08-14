<?php

declare(strict_types=1);

use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Models\WorkosMembership;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use GuzzleHttp\Psr7\Response;
use Workbench\App\Models\Organization;
use Workbench\App\Models\User;

uses(UsesWorkosMockHandler::class);

beforeEach(function (): void {
    $this->migratePackageDatabase();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function membershipResponse(array $overrides = []): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(array_merge([
        'object' => 'organization_membership',
        'id' => 'om_123',
        'user_id' => 'user_abc',
        'organization_id' => 'org_acme',
        'status' => 'active',
        'directory_managed' => false,
        'created_at' => '2026-01-01T00:00:00.000Z',
        'updated_at' => '2026-01-01T00:00:00.000Z',
        'role' => ['slug' => 'admin'],
        'roles' => [['slug' => 'admin']],
        'user' => [
            'id' => 'user_abc',
            'email' => 'member@example.com',
            'email_verified' => true,
            'created_at' => '2026-01-01T00:00:00.000Z',
            'updated_at' => '2026-01-01T00:00:00.000Z',
        ],
    ], $overrides)));
}

it('creates a membership with an explicit role and upserts the projection', function (): void {
    $this->fakeWorkosResponses([membershipResponse()]);

    $membership = Authkit::memberships()->create('org_acme', 'user_abc', role: 'admin');

    expect($membership->id)->toBe('om_123')
        ->and($membership->role->slug)->toBe('admin');

    $request = $this->workosRequestHistory[0]['request'];
    $body = json_decode((string) $request->getBody(), true);

    expect((string) $request->getUri())->toContain('user_management/organization_memberships')
        ->and($request->getMethod())->toBe('POST')
        ->and($body['user_id'])->toBe('user_abc')
        ->and($body['organization_id'])->toBe('org_acme')
        ->and($body['role_slug'])->toBe('admin');

    // The projection row exists before the events pipeline has run at all —
    // the request that created the membership reads its own write.
    expect(WorkosMembership::query()->where('workos_id', 'om_123')->first())
        ->not->toBeNull()
        ->organization_id->toBe('org_acme')
        ->user_id->toBe('user_abc')
        ->role->toBe('admin')
        ->status->toBe('active');
});

it('sends role_slugs when given a list of roles', function (): void {
    $this->fakeWorkosResponses([membershipResponse([
        'roles' => [['slug' => 'admin'], ['slug' => 'billing']],
    ])]);

    Authkit::memberships()->create('org_acme', 'user_abc', role: ['admin', 'billing']);

    $body = json_decode((string) $this->workosRequestHistory[0]['request']->getBody(), true);

    expect($body['role_slugs'])->toBe(['admin', 'billing'])
        ->and($body)->not->toHaveKey('role_slug');
});

it('resolves organization and user models to their workos ids', function (): void {
    $this->fakeWorkosResponses([membershipResponse()]);

    $organization = Organization::query()->createQuietly(['name' => 'Acme', 'workos_id' => 'org_acme']);

    $user = User::query()->create(['name' => 'Member', 'email' => 'member@example.com']);
    $user->forceFill(['workos_id' => 'user_abc'])->saveQuietly();

    Authkit::memberships()->create($organization, $user->refresh(), role: 'admin');

    $body = json_decode((string) $this->workosRequestHistory[0]['request']->getBody(), true);

    expect($body['user_id'])->toBe('user_abc')
        ->and($body['organization_id'])->toBe('org_acme');
});

it('refuses an organization model that has not synced', function (): void {
    $this->fakeWorkosResponses([]);

    $organization = Organization::query()->createQuietly(['name' => 'Unsynced']);

    Authkit::memberships()->create($organization, 'user_abc');
})->throws(InvalidArgumentException::class, 'workos_id is empty');

it('refuses a user model that has no workos id', function (): void {
    $this->fakeWorkosResponses([]);

    $user = User::query()->create(['name' => 'Local Only', 'email' => 'local@example.com']);

    Authkit::memberships()->create('org_acme', $user);
})->throws(InvalidArgumentException::class, 'has no workos_id');

it('updates a membership role and converges the projection row', function (): void {
    WorkosMembership::query()->create([
        'workos_id' => 'om_123',
        'organization_id' => 'org_acme',
        'user_id' => 'user_abc',
        'role' => 'member',
        'status' => 'active',
    ]);

    $this->fakeWorkosResponses([membershipResponse()]);

    $membership = Authkit::memberships()->update('om_123', role: 'admin');

    $request = $this->workosRequestHistory[0]['request'];

    expect($request->getMethod())->toBe('PUT')
        ->and((string) $request->getUri())->toContain('user_management/organization_memberships/om_123')
        ->and($membership->role->slug)->toBe('admin')
        ->and(WorkosMembership::query()->where('workos_id', 'om_123')->value('role'))->toBe('admin');
});

it('deletes a membership and removes its projection row', function (): void {
    WorkosMembership::query()->create([
        'workos_id' => 'om_123',
        'organization_id' => 'org_acme',
        'user_id' => 'user_abc',
        'role' => 'member',
        'status' => 'active',
    ]);

    $this->fakeWorkosResponses([new Response(200, ['Content-Type' => 'application/json'], '{}')]);

    Authkit::memberships()->delete('om_123');

    $request = $this->workosRequestHistory[0]['request'];

    expect($request->getMethod())->toBe('DELETE')
        ->and((string) $request->getUri())->toContain('user_management/organization_memberships/om_123')
        ->and(WorkosMembership::query()->where('workos_id', 'om_123')->exists())->toBeFalse();
});

it('lists memberships with organization, user and status filters', function (): void {
    $this->fakeWorkosResponses([new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
        'object' => 'list',
        'data' => [],
        'list_metadata' => ['before' => null, 'after' => null],
    ]))]);

    Authkit::memberships()->list(organization: 'org_acme', user: 'user_abc', statuses: ['active', 'inactive']);

    $uri = $this->workosRequestHistory[0]['request']->getUri();

    parse_str($uri->getQuery(), $query);

    expect($query['organization_id'])->toBe('org_acme')
        ->and($query['user_id'])->toBe('user_abc')
        ->and($query['statuses'])->toBe(['active', 'inactive']);
});

it('deactivates and reactivates a membership, tracking projection status', function (): void {
    $this->fakeWorkosResponses([
        membershipResponse(['status' => 'inactive']),
        membershipResponse(['status' => 'active']),
    ]);

    Authkit::memberships()->deactivate('om_123');

    expect(WorkosMembership::query()->where('workos_id', 'om_123')->value('status'))->toBe('inactive');

    Authkit::memberships()->reactivate('om_123');

    expect(WorkosMembership::query()->where('workos_id', 'om_123')->value('status'))->toBe('active')
        ->and($this->workosRequestHistory[0]['request']->getUri()->getPath())->toContain('om_123/deactivate')
        ->and($this->workosRequestHistory[1]['request']->getUri()->getPath())->toContain('om_123/reactivate');
});
