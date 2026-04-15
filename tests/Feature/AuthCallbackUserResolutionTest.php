<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use WorkOS\AuthKit\Http\Controllers\AuthController;

beforeEach(function () {
    Schema::create('callback_test_users', function (Blueprint $table) {
        $table->id();
        $table->string('workos_id')->nullable()->unique();
        $table->string('email')->unique();
        $table->string('name');
        $table->timestamps();
    });

    config(['workos.user_model' => AuthCallbackTestUser::class]);
});

afterEach(function () {
    Schema::dropIfExists('callback_test_users');
});

it('creates a new user when no matching user exists', function () {
    $response = [
        'user' => [
            'id' => 'user_new_123',
            'email' => 'new@example.com',
            'first_name' => 'New',
            'last_name' => 'User',
        ],
    ];

    $controller = new AuthController;
    $method = new ReflectionMethod($controller, 'findOrCreateUser');
    $user = $method->invoke($controller, $response);

    expect($user)->toBeInstanceOf(Authenticatable::class);
    $this->assertDatabaseHas('callback_test_users', [
        'workos_id' => 'user_new_123',
        'email' => 'new@example.com',
        'name' => 'New User',
    ]);
});

it('updates existing user found by workos_id', function () {
    AuthCallbackTestUser::create([
        'workos_id' => 'user_existing_123',
        'email' => 'old@example.com',
        'name' => 'Old Name',
    ]);

    $response = [
        'user' => [
            'id' => 'user_existing_123',
            'email' => 'updated@example.com',
            'first_name' => 'Updated',
            'last_name' => 'Name',
        ],
    ];

    $controller = new AuthController;
    $method = new ReflectionMethod($controller, 'findOrCreateUser');
    $user = $method->invoke($controller, $response);

    $this->assertDatabaseCount('callback_test_users', 1);
    $this->assertDatabaseHas('callback_test_users', [
        'workos_id' => 'user_existing_123',
        'email' => 'updated@example.com',
        'name' => 'Updated Name',
    ]);
});

it('finds existing user by email and sets workos_id instead of throwing unique constraint violation', function () {
    AuthCallbackTestUser::create([
        'workos_id' => null,
        'email' => 'nick@example.com',
        'name' => 'Nick',
    ]);

    $response = [
        'user' => [
            'id' => 'user_workos_456',
            'email' => 'nick@example.com',
            'first_name' => 'Nick',
            'last_name' => 'C',
        ],
    ];

    $controller = new AuthController;
    $method = new ReflectionMethod($controller, 'findOrCreateUser');
    $user = $method->invoke($controller, $response);

    $this->assertDatabaseCount('callback_test_users', 1);
    $this->assertDatabaseHas('callback_test_users', [
        'workos_id' => 'user_workos_456',
        'email' => 'nick@example.com',
        'name' => 'Nick C',
    ]);
});

it('finds existing user by email when workos_id differs and updates it', function () {
    AuthCallbackTestUser::create([
        'workos_id' => 'user_old_id',
        'email' => 'same@example.com',
        'name' => 'Same Email',
    ]);

    $response = [
        'user' => [
            'id' => 'user_new_id',
            'email' => 'same@example.com',
            'first_name' => 'Same',
            'last_name' => 'Email',
        ],
    ];

    $controller = new AuthController;
    $method = new ReflectionMethod($controller, 'findOrCreateUser');
    $user = $method->invoke($controller, $response);

    $this->assertDatabaseCount('callback_test_users', 1);
    $this->assertDatabaseHas('callback_test_users', [
        'workos_id' => 'user_new_id',
        'email' => 'same@example.com',
    ]);
});

it('returns null when user data has no id', function () {
    $controller = new AuthController;
    $method = new ReflectionMethod($controller, 'findOrCreateUser');

    $user = $method->invoke($controller, ['user' => []]);
    expect($user)->toBeNull();

    $user = $method->invoke($controller, []);
    expect($user)->toBeNull();
});

class AuthCallbackTestUser extends Authenticatable
{
    protected $table = 'callback_test_users';

    protected $fillable = ['workos_id', 'email', 'name'];
}
