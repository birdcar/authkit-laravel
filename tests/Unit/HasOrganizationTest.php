<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Event;
use WorkOS\AuthKit\Auth\SessionManagerInterface;
use WorkOS\AuthKit\Auth\WorkOSSession;
use WorkOS\AuthKit\Events\OrganizationSwitched;
use WorkOS\AuthKit\Models\Concerns\HasOrganization;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;
use WorkOS\AuthKit\Models\Organization;

class TestUserWithOrganization extends Authenticatable
{
    use HasOrganization;
    use HasWorkOSId;
    use HasWorkOSPermissions;

    protected $table = 'users';

    protected $fillable = ['workos_id', 'email', 'name'];
}

function createTestOrganizationSession(array $roles = [], array $permissions = [], ?string $organizationId = null): WorkOSSession
{
    return new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: null,
        expiresAt: Carbon::now()->addHour(),
        sessionId: 'session_456',
        roles: $roles,
        permissions: $permissions,
        organizationId: $organizationId,
        impersonator: null,
    );
}

beforeEach(function () {
    // Set up database for organization tests
    $this->app['db']->connection()->getSchemaBuilder()->create('users', function ($table) {
        $table->id();
        $table->string('workos_id')->nullable()->unique();
        $table->string('email');
        $table->string('name');
        $table->timestamps();
    });

    $this->app['db']->connection()->getSchemaBuilder()->create('organizations', function ($table) {
        $table->id();
        $table->string('workos_id')->unique();
        $table->string('name');
        $table->string('slug')->nullable();
        $table->timestamps();
    });

    $this->app['db']->connection()->getSchemaBuilder()->create('organization_user', function ($table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
        $table->string('role')->nullable();
        $table->timestamps();
        $table->unique(['user_id', 'organization_id']);
    });
});

it('returns organizations relationship', function () {
    $user = TestUserWithOrganization::create([
        'workos_id' => 'user_123',
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    expect($user->organizations())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsToMany::class);
});

it('checks if user belongs to organization', function () {
    $user = TestUserWithOrganization::create([
        'workos_id' => 'user_123',
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    $org = Organization::create([
        'workos_id' => 'org_456',
        'name' => 'Test Organization',
    ]);

    expect($user->belongsToOrganization('org_456'))->toBeFalse();

    $user->organizations()->attach($org->id, ['role' => 'member']);

    expect($user->belongsToOrganization('org_456'))->toBeTrue();
    expect($user->belongsToOrganization('org_other'))->toBeFalse();
});

it('gets organization role', function () {
    $user = TestUserWithOrganization::create([
        'workos_id' => 'user_123',
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    $org = Organization::create([
        'workos_id' => 'org_456',
        'name' => 'Test Organization',
    ]);

    $user->organizations()->attach($org->id, ['role' => 'admin']);

    expect($user->organizationRole('org_456'))->toBe('admin');
    expect($user->organizationRole('org_other'))->toBeNull();
});

it('checks organization role', function () {
    $user = TestUserWithOrganization::create([
        'workos_id' => 'user_123',
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    $org = Organization::create([
        'workos_id' => 'org_456',
        'name' => 'Test Organization',
    ]);

    $user->organizations()->attach($org->id, ['role' => 'admin']);

    expect($user->hasOrganizationRole('org_456', 'admin'))->toBeTrue();
    expect($user->hasOrganizationRole('org_456', 'member'))->toBeFalse();
});

it('gets current organization', function () {
    $user = TestUserWithOrganization::create([
        'workos_id' => 'user_123',
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    $org = Organization::create([
        'workos_id' => 'org_456',
        'name' => 'Test Organization',
    ]);

    $user->organizations()->attach($org->id);

    $session = createTestOrganizationSession(organizationId: 'org_456');
    $user->setWorkOSSession($session);

    $currentOrg = $user->currentOrganization();
    expect($currentOrg)->not->toBeNull();
    expect($currentOrg->workos_id)->toBe('org_456');
});

it('returns null for current organization when no session', function () {
    $user = TestUserWithOrganization::create([
        'workos_id' => 'user_123',
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    expect($user->currentOrganization())->toBeNull();
});

it('switches organization and fires event', function () {
    Event::fake([OrganizationSwitched::class]);

    $user = TestUserWithOrganization::create([
        'workos_id' => 'user_123',
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    $org = Organization::create([
        'workos_id' => 'org_456',
        'name' => 'Test Organization',
    ]);

    $user->organizations()->attach($org->id);

    // Mock the session manager
    $sessionManager = Mockery::mock(SessionManagerInterface::class);
    $sessionManager->shouldReceive('setOrganizationId')
        ->once()
        ->with('org_456');
    $this->app->instance(SessionManagerInterface::class, $sessionManager);

    $result = $user->switchOrganization('org_456');

    expect($result)->toBeTrue();

    Event::assertDispatched(OrganizationSwitched::class, function ($event) use ($user) {
        return $event->user->is($user) && $event->organizationId === 'org_456';
    });
});

it('fails to switch to organization user does not belong to', function () {
    Event::fake([OrganizationSwitched::class]);

    $user = TestUserWithOrganization::create([
        'workos_id' => 'user_123',
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    $result = $user->switchOrganization('org_nonexistent');

    expect($result)->toBeFalse();

    Event::assertNotDispatched(OrganizationSwitched::class);
});

it('checks organization permission from session', function () {
    $user = TestUserWithOrganization::create([
        'workos_id' => 'user_123',
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    $session = createTestOrganizationSession(
        permissions: ['read', 'write'],
        organizationId: 'org_456',
    );
    $user->setWorkOSSession($session);

    expect($user->hasOrganizationPermission('org_456', 'read'))->toBeTrue();
    expect($user->hasOrganizationPermission('org_456', 'delete'))->toBeFalse();
    // Wrong org context
    expect($user->hasOrganizationPermission('org_other', 'read'))->toBeFalse();
});

it('returns false for organization permission when no session', function () {
    $user = TestUserWithOrganization::create([
        'workos_id' => 'user_123',
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    expect($user->hasOrganizationPermission('org_456', 'read'))->toBeFalse();
});
