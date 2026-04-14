<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use WorkOS\AuthKit\Events\Sync\WorkOSMembershipCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSUserCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSUserUpdated;
use WorkOS\AuthKit\WorkOSServiceProvider;

beforeEach(function () {
    Schema::create('listener_config_users', function (Blueprint $table) {
        $table->id();
        $table->string('workos_id')->nullable()->unique();
        $table->string('email');
        $table->string('name');
        $table->timestamps();
    });

    config(['workos.user_model' => ListenerConfigUser::class]);

    ListenerConfigCustomListener::$handled = false;
});

afterEach(function () {
    Schema::dropIfExists('listener_config_users');
});

it('registers default listeners when no config overrides exist', function () {
    config(['workos.sync.listeners' => []]);

    $listeners = Event::getListeners(WorkOSUserCreated::class);

    expect($listeners)->not->toBeEmpty();
});

it('replaces a default listener with a custom one', function () {
    Event::forget(WorkOSUserCreated::class);

    config([
        'workos.sync.listeners' => [
            WorkOSUserCreated::class => ListenerConfigCustomListener::class,
        ],
    ]);

    (new WorkOSServiceProvider(app()))->boot();

    event(new WorkOSUserCreated(['id' => 'user_123', 'email' => 'test@test.com']));

    expect(ListenerConfigCustomListener::$handled)->toBeTrue();
});

it('disables a listener when config maps event to null', function () {
    Event::forget(WorkOSUserUpdated::class);

    config([
        'workos.sync.listeners' => [
            WorkOSUserUpdated::class => null,
        ],
    ]);

    (new WorkOSServiceProvider(app()))->boot();

    $listeners = Event::getListeners(WorkOSUserUpdated::class);

    expect($listeners)->toBeEmpty();
});

it('keeps default listeners for events not in overrides', function () {
    config([
        'workos.sync.listeners' => [
            WorkOSUserCreated::class => null,
        ],
    ]);

    (new WorkOSServiceProvider(app()))->boot();

    $orgListeners = Event::getListeners(WorkOSOrganizationCreated::class);
    $membershipListeners = Event::getListeners(WorkOSMembershipCreated::class);

    expect($orgListeners)->not->toBeEmpty()
        ->and($membershipListeners)->not->toBeEmpty();
});

it('handles mixed config: replaced, disabled, and default', function () {
    Event::forget(WorkOSUserCreated::class);
    Event::forget(WorkOSUserUpdated::class);
    Event::forget(WorkOSOrganizationCreated::class);
    Event::forget(WorkOSMembershipCreated::class);

    config([
        'workos.sync.listeners' => [
            WorkOSUserCreated::class => ListenerConfigCustomListener::class,
            WorkOSUserUpdated::class => null,
        ],
    ]);

    (new WorkOSServiceProvider(app()))->boot();

    expect(Event::getListeners(WorkOSUserCreated::class))->not->toBeEmpty()
        ->and(Event::getListeners(WorkOSUserUpdated::class))->toBeEmpty()
        ->and(Event::getListeners(WorkOSOrganizationCreated::class))->not->toBeEmpty()
        ->and(Event::getListeners(WorkOSMembershipCreated::class))->not->toBeEmpty();
});

// --- Test fixtures ---

class ListenerConfigUser extends Model
{
    protected $table = 'listener_config_users';

    protected $fillable = ['workos_id', 'email', 'name'];

    public static function findByWorkOSId(string $id): ?static
    {
        return static::where('workos_id', $id)->first();
    }
}

class ListenerConfigCustomListener
{
    public static bool $handled = false;

    public function handle(WorkOSUserCreated $event): void
    {
        self::$handled = true;
    }
}
