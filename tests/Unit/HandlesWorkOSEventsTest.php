<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use WorkOS\AuthKit\Events\Sync\WorkOSMembershipCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSUserCreated;
use WorkOS\AuthKit\Events\WorkOSEventReceived;
use WorkOS\AuthKit\Facades\WorkOS;
use WorkOS\AuthKit\Listeners\Concerns\HandlesWorkOSEvents;

beforeEach(function () {
    Schema::create('trait_test_users', function (Blueprint $table) {
        $table->id();
        $table->string('workos_id')->nullable()->unique();
        $table->string('email');
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('trait_test_organizations', function (Blueprint $table) {
        $table->id();
        $table->string('workos_id')->nullable()->unique();
        $table->string('name');
        $table->timestamps();
    });

    config([
        'workos.user_model' => TraitTestUser::class,
        'workos.organization_model' => TraitTestOrganization::class,
    ]);
});

afterEach(function () {
    Schema::dropIfExists('trait_test_users');
    Schema::dropIfExists('trait_test_organizations');
});

it('resolves user by workos_id from user event', function () {
    TraitTestUser::create(['workos_id' => 'user_123', 'email' => 'test@example.com', 'name' => 'Test']);
    $listener = new TraitTestListener;
    $event = new WorkOSUserCreated(['id' => 'user_123', 'email' => 'test@example.com']);

    $user = $listener->testResolveUser($event);

    expect($user)->not->toBeNull()
        ->and($user->workos_id)->toBe('user_123');
});

it('resolves user by user_id from membership event', function () {
    TraitTestUser::create(['workos_id' => 'user_456', 'email' => 'test@example.com', 'name' => 'Test']);
    $listener = new TraitTestListener;
    $event = new WorkOSMembershipCreated([
        'id' => 'membership_1',
        'user_id' => 'user_456',
        'organization_id' => 'org_1',
    ]);

    $user = $listener->testResolveUser($event);

    expect($user)->not->toBeNull()
        ->and($user->workos_id)->toBe('user_456');
});

it('returns null when user not found', function () {
    $listener = new TraitTestListener;
    $event = new WorkOSUserCreated(['id' => 'user_nonexistent', 'email' => 'x@x.com']);

    expect($listener->testResolveUser($event))->toBeNull();
});

it('returns null when event has no user ID field', function () {
    $listener = new TraitTestListener;
    $event = new WorkOSEventReceived('custom.event', ['foo' => 'bar']);

    expect($listener->testResolveUser($event))->toBeNull();
});

it('resolves organization by workos_id', function () {
    TraitTestOrganization::create(['workos_id' => 'org_123', 'name' => 'Acme']);
    $listener = new TraitTestListener;
    $event = new WorkOSEventReceived('organization.created', ['id' => 'org_123', 'name' => 'Acme']);

    $org = $listener->testResolveOrganization($event);

    expect($org)->not->toBeNull()
        ->and($org->workos_id)->toBe('org_123');
});

it('returns null when organization not found', function () {
    $listener = new TraitTestListener;
    $event = new WorkOSEventReceived('organization.created', ['id' => 'org_nonexistent']);

    expect($listener->testResolveOrganization($event))->toBeNull();
});

it('delegates audit to WorkOS facade', function () {
    $fake = WorkOS::fake();
    $listener = new TraitTestListener;

    $listener->testAudit('user.synced', ['user_id' => 'user_123']);

    $fake->assertAudited('user.synced');
    WorkOS::restore();
});

it('logs with event class name context for typed events', function () {
    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return $message === 'User synced'
                && $context['workos_event'] === 'WorkOSUserCreated';
        });

    $listener = new TraitTestListener;
    $event = new WorkOSUserCreated(['id' => 'user_123', 'email' => 'test@example.com']);

    $listener->testLogEvent('User synced', $event);
});

it('logs with event type string for WorkOSEventReceived', function () {
    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return $context['workos_event'] === 'user.created';
        });

    $listener = new TraitTestListener;
    $event = new WorkOSEventReceived('user.created', ['id' => 'user_123']);

    $listener->testLogEvent('Processing', $event);
});

it('executes callback within transaction', function () {
    $listener = new TraitTestListener;

    $result = $listener->testWithinTransaction(function () {
        TraitTestUser::create(['workos_id' => 'user_tx', 'email' => 'tx@test.com', 'name' => 'TX']);

        return 'committed';
    });

    expect($result)->toBe('committed')
        ->and(TraitTestUser::where('workos_id', 'user_tx')->exists())->toBeTrue();
});

it('rolls back transaction on exception', function () {
    $listener = new TraitTestListener;

    try {
        $listener->testWithinTransaction(function () {
            TraitTestUser::create(['workos_id' => 'user_rollback', 'email' => 'rb@test.com', 'name' => 'RB']);
            throw new RuntimeException('fail');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(TraitTestUser::where('workos_id', 'user_rollback')->exists())->toBeFalse();
});

// --- Test fixtures ---

class TraitTestListener
{
    use HandlesWorkOSEvents;

    public function testResolveUser(object $event): ?Authenticatable
    {
        return $this->resolveUser($event);
    }

    public function testResolveOrganization(object $event): ?Model
    {
        return $this->resolveOrganization($event);
    }

    public function testAudit(string $action, array $metadata = []): void
    {
        $this->audit($action, $metadata);
    }

    public function testLogEvent(string $message, object $event, array $context = []): void
    {
        $this->logEvent($message, $event, $context);
    }

    public function testWithinTransaction(callable $callback): mixed
    {
        return $this->withinTransaction($callback);
    }
}

class TraitTestUser extends Authenticatable
{
    protected $table = 'trait_test_users';

    protected $fillable = ['workos_id', 'email', 'name'];
}

class TraitTestOrganization extends Model
{
    protected $table = 'trait_test_organizations';

    protected $fillable = ['workos_id', 'name'];
}
