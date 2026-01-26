<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\AuthKit\Auth\WorkOSSession;
use WorkOS\AuthKit\Events\OrganizationSwitched;
use WorkOS\AuthKit\Models\Concerns\HasOrganization;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;

class TestSwitchOrganization extends Model
{
    protected $table = 'organizations';

    protected $fillable = ['workos_id', 'name', 'slug'];
}

class TestSwitchUser extends Authenticatable
{
    use HasOrganization;
    use HasWorkOSId;
    use HasWorkOSPermissions;

    protected $table = 'users';

    protected $fillable = ['workos_id', 'email', 'name'];
}

function createSwitchTestSession(?string $organizationId = null): WorkOSSession
{
    return new WorkOSSession(
        userId: 'user_123',
        accessToken: 'token_abc',
        refreshToken: null,
        expiresAt: Carbon::now()->addHour(),
        sessionId: 'session_456',
        roles: [],
        permissions: [],
        organizationId: $organizationId,
        impersonator: null,
    );
}

beforeEach(function () {
    // Configure models for tests
    config(['workos.user_model' => TestSwitchUser::class]);
    config(['workos.organization_model' => TestSwitchOrganization::class]);
    // Disable auth requirement for organization routes in tests
    config(['workos.routes.middleware' => ['web']]);

    // Set up database
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

    $this->app['db']->connection()->getSchemaBuilder()->create('organization_memberships', function ($table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
        $table->string('role')->nullable();
        $table->timestamps();
        $table->unique(['user_id', 'organization_id']);
    });
});

it('switches organization via endpoint', function () {
    Event::fake([OrganizationSwitched::class]);

    $user = TestSwitchUser::create([
        'workos_id' => 'user_123',
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    $org = TestSwitchOrganization::create([
        'workos_id' => 'org_456',
        'name' => 'Test Organization',
    ]);

    $user->organizations()->attach($org->id);

    $session = createSwitchTestSession();
    $user->setWorkOSSession($session);

    // Store session data
    $this->app->make(SessionManager::class)->store([
        'user' => ['id' => 'user_123'],
        'access_token' => 'token_abc',
        'expires_in' => 3600,
    ]);

    // Register a simple test route for switching
    Route::post('/test-org-switch', function (\Illuminate\Http\Request $request) {
        return app(\WorkOS\AuthKit\Http\Controllers\OrganizationController::class)->switch($request);
    })->middleware(['web']);

    $response = $this->actingAs($user)
        ->post('/test-org-switch', [
            'organization_id' => 'org_456',
        ]);

    $response->assertRedirect('/');

    Event::assertDispatched(OrganizationSwitched::class);
});

it('fails to switch to organization user does not belong to', function () {
    Event::fake([OrganizationSwitched::class]);

    $user = TestSwitchUser::create([
        'workos_id' => 'user_123',
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    $session = createSwitchTestSession();
    $user->setWorkOSSession($session);

    // Register a simple test route for switching
    Route::post('/test-org-switch-fail', function (\Illuminate\Http\Request $request) {
        return app(\WorkOS\AuthKit\Http\Controllers\OrganizationController::class)->switch($request);
    })->middleware(['web']);

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->post('/test-org-switch-fail', [
            'organization_id' => 'org_nonexistent',
        ]);

    $response->assertRedirect('/dashboard');
    $response->assertSessionHasErrors('organization');

    Event::assertNotDispatched(OrganizationSwitched::class);
});

it('requires organization_id parameter', function () {
    $user = TestSwitchUser::create([
        'workos_id' => 'user_123',
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    // Register a simple test route for switching
    Route::post('/test-org-switch-validate', function (\Illuminate\Http\Request $request) {
        return app(\WorkOS\AuthKit\Http\Controllers\OrganizationController::class)->switch($request);
    })->middleware(['web']);

    $response = $this->actingAs($user)
        ->post('/test-org-switch-validate', []);

    $response->assertSessionHasErrors('organization_id');
});

it('middleware blocks unauthenticated users', function () {
    Route::get('/test-org', function () {
        return 'OK';
    })->middleware(['workos.organization']);

    $response = $this->get('/test-org');

    $response->assertForbidden();
});

it('middleware blocks users without selected organization', function () {
    Route::get('/test-org-no-org', function () {
        return 'OK';
    })->middleware(['workos.organization']);

    $user = TestSwitchUser::create([
        'workos_id' => 'user_123',
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    // Session without organization
    $this->app->make(SessionManager::class)->store([
        'user' => ['id' => 'user_123'],
        'access_token' => 'token_abc',
        'expires_in' => 3600,
    ]);

    $response = $this->actingAs($user)->get('/test-org-no-org');

    $response->assertForbidden();
});

it('middleware allows users with valid organization', function () {
    Route::get('/test-org-valid', function () {
        return 'OK';
    })->middleware(['workos.organization']);

    $user = TestSwitchUser::create([
        'workos_id' => 'user_123',
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    $org = TestSwitchOrganization::create([
        'workos_id' => 'org_456',
        'name' => 'Test Organization',
    ]);

    $user->organizations()->attach($org->id);

    // Session with organization
    $this->app->make(SessionManager::class)->store([
        'user' => ['id' => 'user_123'],
        'access_token' => 'token_abc',
        'expires_in' => 3600,
        'organization_id' => 'org_456',
    ]);

    $session = createSwitchTestSession(organizationId: 'org_456');
    $user->setWorkOSSession($session);

    $response = $this->actingAs($user)->get('/test-org-valid');

    $response->assertOk();
});

it('middleware checks role when specified', function () {
    Route::get('/test-admin', function () {
        return 'OK';
    })->middleware(['workos.organization:admin']);

    $user = TestSwitchUser::create([
        'workos_id' => 'user_123',
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    $org = TestSwitchOrganization::create([
        'workos_id' => 'org_456',
        'name' => 'Test Organization',
    ]);

    // Attach with 'member' role, not 'admin'
    $user->organizations()->attach($org->id, ['role' => 'member']);

    // Session with organization
    $this->app->make(SessionManager::class)->store([
        'user' => ['id' => 'user_123'],
        'access_token' => 'token_abc',
        'expires_in' => 3600,
        'organization_id' => 'org_456',
    ]);

    $session = createSwitchTestSession(organizationId: 'org_456');
    $user->setWorkOSSession($session);

    $response = $this->actingAs($user)->get('/test-admin');

    $response->assertForbidden();
});
