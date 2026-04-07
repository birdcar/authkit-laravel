<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use WorkOS\AuthKit\Install\AuthSystemInstaller;

afterEach(function () {
    Mockery::close();
});

function makeCommand(): Command
{
    $command = Mockery::mock(Command::class);
    $command->allows('option')->with('force')->andReturn(false);
    $command->allows('info')->andReturnSelf();
    $command->allows('warn')->andReturnSelf();
    $command->allows('line')->andReturnSelf();
    $command->allows('newLine')->andReturnSelf();

    return $command;
}

function callPrivate(object $object, string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionClass($object);
    $m = $reflection->getMethod($method);
    $m->setAccessible(true);

    return $m->invoke($object, ...$args);
}

it('prints manual guard instructions when auth.php has unexpected format', function () {
    $installer = new AuthSystemInstaller;
    $authConfigPath = config_path('auth.php');
    File::ensureDirectoryExists(config_path());
    File::put($authConfigPath, "<?php\nreturn ['no_guards_key' => []];");

    $command = Mockery::mock(Command::class);
    $command->allows('option')->andReturn(false);
    $command->allows('line')->andReturnSelf();
    $command->shouldReceive('warn')
        ->with(Mockery::pattern('/Could not automatically/'))
        ->once()
        ->andReturnSelf();
    $command->allows('info')->andReturnSelf();

    callPrivate($installer, 'updateAuthConfig', $command);

    File::delete($authConfigPath);
});

it('does not call File::put when neither guards nor providers regex matched', function () {
    $installer = new AuthSystemInstaller;
    $authConfigPath = config_path('auth.php');
    File::ensureDirectoryExists(config_path());
    File::put($authConfigPath, "<?php\nreturn ['no_guards_key' => []];");

    $originalContent = File::get($authConfigPath);
    $command = makeCommand();

    callPrivate($installer, 'updateAuthConfig', $command);

    expect(File::get($authConfigPath))->toBe($originalContent);

    File::delete($authConfigPath);
});

it('successfully updates standard auth.php format', function () {
    $installer = new AuthSystemInstaller;
    $authConfigPath = config_path('auth.php');
    File::ensureDirectoryExists(config_path());
    $standardAuthContent = <<<'PHP'
<?php

return [
    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],
    ],
];
PHP;
    File::put($authConfigPath, $standardAuthContent);

    $command = Mockery::mock(Command::class);
    $command->allows('option')->andReturn(false);
    $command->allows('warn')->andReturnSelf();
    $command->allows('line')->andReturnSelf();
    $command->shouldReceive('info')
        ->with('Updated config/auth.php with WorkOS guard')
        ->once()
        ->andReturnSelf();

    callPrivate($installer, 'updateAuthConfig', $command);

    $updatedContent = File::get($authConfigPath);
    expect($updatedContent)->toContain("'workos'");

    File::delete($authConfigPath);
});

it('skips when workos guard already present in auth.php', function () {
    $installer = new AuthSystemInstaller;
    $authConfigPath = config_path('auth.php');
    File::ensureDirectoryExists(config_path());
    File::put($authConfigPath, "<?php\nreturn ['guards' => ['workos' => ['driver' => 'workos']]];");

    $command = Mockery::mock(Command::class);
    $command->allows('option')->andReturn(false);
    $command->allows('warn')->andReturnSelf();
    $command->allows('line')->andReturnSelf();
    $command->shouldReceive('info')
        ->with('WorkOS guard already configured in auth.php')
        ->once()
        ->andReturnSelf();

    callPrivate($installer, 'updateAuthConfig', $command);

    File::delete($authConfigPath);
});

it('prints manual trait instructions when User model has unexpected format', function () {
    $installer = new AuthSystemInstaller;
    $userModelPath = app_path('Models/User.php');
    File::ensureDirectoryExists(app_path('Models'));

    $originalContent = File::exists($userModelPath) ? File::get($userModelPath) : null;

    // A User model without standard class/use structure — no class declaration
    File::put($userModelPath, "<?php\n// No class declaration here\n");

    $command = Mockery::mock(Command::class);
    $command->allows('option')->andReturn(false);
    $command->allows('info')->andReturnSelf();
    $command->allows('line')->andReturnSelf();
    $command->shouldReceive('warn')
        ->with(Mockery::pattern('/Could not automatically/'))
        ->once()
        ->andReturnSelf();

    callPrivate($installer, 'updateUserModel', $command);

    if ($originalContent !== null) {
        File::put($userModelPath, $originalContent);
    } else {
        File::delete($userModelPath);
    }
});

it('successfully adds traits to standard User model', function () {
    $installer = new AuthSystemInstaller;
    $userModelPath = app_path('Models/User.php');
    File::ensureDirectoryExists(app_path('Models'));

    $originalContent = File::exists($userModelPath) ? File::get($userModelPath) : null;

    $freshUserModel = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];
}
PHP;

    File::put($userModelPath, $freshUserModel);

    $command = Mockery::mock(Command::class);
    $command->allows('option')->andReturn(false);
    $command->allows('warn')->andReturnSelf();
    $command->allows('line')->andReturnSelf();
    $command->shouldReceive('info')
        ->with('Added WorkOS traits to User model')
        ->once()
        ->andReturnSelf();

    callPrivate($installer, 'updateUserModel', $command);

    $updatedContent = File::get($userModelPath);
    expect($updatedContent)->toContain('HasWorkOSId');
    expect($updatedContent)->toContain('HasWorkOSPermissions');

    if ($originalContent !== null) {
        File::put($userModelPath, $originalContent);
    } else {
        File::delete($userModelPath);
    }
});
