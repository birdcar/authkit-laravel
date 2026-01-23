<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;

beforeEach(function () {
    Schema::create('workos_test_users', function (Blueprint $table) {
        $table->id();
        $table->string('workos_id')->nullable()->unique();
        $table->string('email')->unique();
        $table->string('name');
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('workos_test_users');
});

it('initializes workos_id as fillable', function () {
    $model = new WorkOSTestUserModel;

    expect($model->isFillable('workos_id'))->toBeTrue();
});

it('returns workos_id column name', function () {
    $model = new WorkOSTestUserModel;

    expect($model->getWorkOSIdColumn())->toBe('workos_id');
});

it('returns workos_id value', function () {
    $model = new WorkOSTestUserModel;
    $model->workos_id = 'user_123';

    expect($model->getWorkOSId())->toBe('user_123');
});

it('returns null when workos_id is not set', function () {
    $model = new WorkOSTestUserModel;

    expect($model->getWorkOSId())->toBeNull();
});

it('returns workos_id as auth identifier name', function () {
    $model = new WorkOSTestUserModel;

    expect($model->getAuthIdentifierName())->toBe('workos_id');
});

it('returns workos_id as auth identifier', function () {
    $model = new WorkOSTestUserModel;
    $model->workos_id = 'user_456';

    expect($model->getAuthIdentifier())->toBe('user_456');
});

it('finds user by workos_id', function () {
    WorkOSTestUserModel::create([
        'workos_id' => 'user_123',
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    $found = WorkOSTestUserModel::findByWorkOSId('user_123');

    expect($found)->not->toBeNull()
        ->and($found->email)->toBe('test@example.com');
});

it('returns null when user not found by workos_id', function () {
    $found = WorkOSTestUserModel::findByWorkOSId('nonexistent');

    expect($found)->toBeNull();
});

it('creates new user from workos data', function () {
    $workosUser = [
        'id' => 'user_new',
        'email' => 'new@example.com',
        'first_name' => 'New',
        'last_name' => 'User',
    ];

    $user = WorkOSTestUserModel::findOrCreateByWorkOS($workosUser);

    expect($user->workos_id)->toBe('user_new')
        ->and($user->email)->toBe('new@example.com')
        ->and($user->name)->toBe('New User');
});

it('finds existing user from workos data', function () {
    WorkOSTestUserModel::create([
        'workos_id' => 'user_existing',
        'email' => 'existing@example.com',
        'name' => 'Existing User',
    ]);

    $workosUser = [
        'id' => 'user_existing',
        'email' => 'updated@example.com',
        'first_name' => 'Updated',
        'last_name' => 'Name',
    ];

    $user = WorkOSTestUserModel::findOrCreateByWorkOS($workosUser);

    expect($user->workos_id)->toBe('user_existing')
        ->and($user->email)->toBe('existing@example.com')
        ->and($user->name)->toBe('Existing User');
});

it('handles missing name fields in workos data', function () {
    $workosUser = [
        'id' => 'user_noname',
        'email' => 'noname@example.com',
    ];

    $user = WorkOSTestUserModel::findOrCreateByWorkOS($workosUser);

    expect($user->workos_id)->toBe('user_noname')
        ->and($user->name)->toBe('');
});

class WorkOSTestUserModel extends Model
{
    use HasWorkOSId;

    protected $table = 'workos_test_users';

    protected $fillable = ['email', 'name'];
}
