<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User as Authenticatable;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;

class TestSyncUser extends Authenticatable
{
    use HasWorkOSId;
    use HasWorkOSPermissions;

    protected $table = 'users';

    protected $fillable = ['workos_id', 'email', 'name'];
}

beforeEach(function () {
    // Set up database
    $this->app['db']->connection()->getSchemaBuilder()->create('users', function ($table) {
        $table->id();
        $table->string('workos_id')->nullable()->unique();
        $table->string('email');
        $table->string('name');
        $table->timestamps();
    });

    // Configure user model
    config(['workos.user_model' => TestSyncUser::class]);
});

it('fails when user model does not exist', function () {
    config(['workos.user_model' => 'NonExistent\\Model']);

    $this->artisan('workos:sync-users')
        ->expectsOutput('User model NonExistent\\Model does not exist.')
        ->assertFailed();
});

it('fails when user model lacks findOrCreateByWorkOS method', function () {
    // Create a model without the trait
    $this->app['db']->connection()->getSchemaBuilder()->dropIfExists('users');
    $this->app['db']->connection()->getSchemaBuilder()->create('simple_users', function ($table) {
        $table->id();
        $table->string('email');
        $table->timestamps();
    });

    config(['workos.user_model' => SimpleTestUser::class]);

    $this->artisan('workos:sync-users')
        ->assertFailed();
});

class SimpleTestUser extends Authenticatable
{
    protected $table = 'simple_users';

    protected $fillable = ['email'];
}
