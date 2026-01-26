<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AuthSystemInstaller implements ComponentInstaller
{
    public function install(Command $command): void
    {
        $this->publishConfig($command);
        $this->publishMigrations($command);
        $this->publishOrganizationModel($command);
        $this->updateAuthConfig($command);
        $this->updateUserModel($command);
    }

    private function publishConfig(Command $command): void
    {
        $command->callSilently('vendor:publish', [
            '--tag' => 'workos-config',
            '--force' => $command->option('force'),
        ]);
        $command->info('Published config/workos.php');
    }

    private function publishMigrations(Command $command): void
    {
        $command->callSilently('vendor:publish', [
            '--tag' => 'workos-migrations',
        ]);
        $command->info('Published migrations');
    }

    private function publishOrganizationModel(Command $command): void
    {
        // Ask if they have an existing model to map to WorkOS Organizations
        $hasExisting = $command->confirm(
            'Do you have an existing model to use for organizations (e.g., Team, Workspace)?',
            false
        );

        if ($hasExisting) {
            $this->configureExistingOrganizationModel($command);

            return;
        }

        $this->createOrganizationModel($command);
    }

    private function configureExistingOrganizationModel(Command $command): void
    {
        $modelClass = $command->ask(
            'Enter the fully qualified class name',
            'App\\Models\\Team'
        );

        // Normalize the class name
        $modelClass = ltrim($modelClass, '\\');

        if (! class_exists($modelClass)) {
            $command->warn("Class {$modelClass} not found. Make sure it exists before running migrations.");
        }

        $this->updateWorkosConfigOrganizationModel($modelClass);
        $command->info("Configured {$modelClass} as organization model");

        $command->newLine();
        $command->line('<fg=yellow>Add these to your '.class_basename($modelClass).' model:</>');
        $command->line('  <fg=cyan>use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;</>');
        $command->newLine();
        $command->line('<fg=yellow>Required columns (add via migration if missing):</>');
        $command->line('  <fg=cyan>$table->string(\'workos_id\')->nullable()->unique();</>');
    }

    private function createOrganizationModel(Command $command): void
    {
        $modelPath = app_path('Models/Organization.php');

        if (File::exists($modelPath)) {
            $command->info('Organization model already exists');
            $this->updateWorkosConfigOrganizationModel('App\\Models\\Organization');

            return;
        }

        $stub = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Organization extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'workos_id',
        'name',
        'slug',
    ];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_memberships')
            ->withPivot('role')
            ->withTimestamps();
    }

    public static function findByWorkOSId(string $workosId): ?static
    {
        return static::query()->where('workos_id', $workosId)->first();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function findOrCreateByWorkOS(array $data): static
    {
        return static::query()->firstOrCreate(
            ['workos_id' => $data['id']],
            [
                'name' => $data['name'],
                'slug' => $data['slug'] ?? null,
            ]
        );
    }
}
PHP;

        File::ensureDirectoryExists(app_path('Models'));
        File::put($modelPath, $stub);
        $command->info('Created app/Models/Organization.php');

        $this->updateWorkosConfigOrganizationModel('App\\Models\\Organization');
    }

    private function updateWorkosConfigOrganizationModel(string $modelClass): void
    {
        $configPath = config_path('workos.php');

        if (! File::exists($configPath)) {
            return;
        }

        $contents = File::get($configPath);
        $escapedClass = str_replace('\\', '\\\\', $modelClass);

        $updated = preg_replace(
            "/'organization_model'\s*=>\s*env\([^,]+,\s*[^)]+\)/",
            "'organization_model' => env('WORKOS_ORGANIZATION_MODEL', {$escapedClass}::class)",
            $contents
        );

        if ($updated !== null && $updated !== $contents) {
            File::put($configPath, $updated);
        }
    }

    private function updateAuthConfig(Command $command): void
    {
        $authConfigPath = config_path('auth.php');

        if (! File::exists($authConfigPath)) {
            $command->warn('Could not find config/auth.php - please add the WorkOS guard manually');

            return;
        }

        $contents = File::get($authConfigPath);

        if (str_contains($contents, "'workos'")) {
            $command->info('WorkOS guard already configured in auth.php');

            return;
        }

        $guardToAdd = <<<'PHP'
        'workos' => [
            'driver' => 'workos',
            'provider' => 'users',
        ],

PHP;

        $providerToAdd = <<<'PHP'
        'workos' => [
            'driver' => 'eloquent',
            'model' => env('WORKOS_USER_MODEL', App\Models\User::class),
        ],

PHP;

        if (str_contains($contents, "'guards' => [")) {
            $result = preg_replace(
                "/('guards'\s*=>\s*\[)/",
                "$1\n{$guardToAdd}",
                $contents
            );
            if ($result !== null) {
                $contents = $result;
            }
        }

        if (str_contains($contents, "'providers' => [")) {
            $result = preg_replace(
                "/('providers'\s*=>\s*\[)/",
                "$1\n{$providerToAdd}",
                $contents
            );
            if ($result !== null) {
                $contents = $result;
            }
        }

        File::put($authConfigPath, $contents);
        $command->info('Updated config/auth.php with WorkOS guard');
    }

    private function updateUserModel(Command $command): void
    {
        $userModelPath = app_path('Models/User.php');

        if (! File::exists($userModelPath)) {
            $command->warn('Could not find app/Models/User.php - please add the WorkOS traits manually:');
            $command->line('  <fg=cyan>use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;</>');
            $command->line('  <fg=cyan>use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;</>');

            return;
        }

        $contents = File::get($userModelPath);

        $hasWorkOSId = str_contains($contents, 'HasWorkOSId');
        $hasWorkOSPermissions = str_contains($contents, 'HasWorkOSPermissions');

        if ($hasWorkOSId && $hasWorkOSPermissions) {
            $command->info('WorkOS traits already present in User model');

            return;
        }

        $modified = false;
        $contents = $this->addTraitImports($contents, $hasWorkOSId, $hasWorkOSPermissions, $modified);
        $contents = $this->addTraitUsages($contents, $hasWorkOSId, $hasWorkOSPermissions, $modified);

        if ($modified) {
            File::put($userModelPath, $contents);
            $command->info('Added WorkOS traits to User model');
        }
    }

    private function addTraitImports(string $contents, bool $hasWorkOSId, bool $hasWorkOSPermissions, bool &$modified): string
    {
        $importsToAdd = [];
        if (! $hasWorkOSId) {
            $importsToAdd[] = 'use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;';
        }
        if (! $hasWorkOSPermissions) {
            $importsToAdd[] = 'use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;';
        }

        if ($importsToAdd === [] || ! preg_match('/^use [^;]+;/m', $contents)) {
            return $contents;
        }

        $result = preg_replace(
            '/(^use [^;]+;)(?!.*^use [^;]+;)/ms',
            "$1\n".implode("\n", $importsToAdd),
            $contents
        );

        if ($result !== null && $result !== $contents) {
            $modified = true;

            return $result;
        }

        return $contents;
    }

    private function addTraitUsages(string $contents, bool $hasWorkOSId, bool $hasWorkOSPermissions, bool &$modified): string
    {
        $traitsToAdd = [];
        if (! $hasWorkOSId) {
            $traitsToAdd[] = 'HasWorkOSId';
        }
        if (! $hasWorkOSPermissions) {
            $traitsToAdd[] = 'HasWorkOSPermissions';
        }

        if ($traitsToAdd === []) {
            return $contents;
        }

        // Check if there's already a trait use statement we can append to
        if (preg_match('/^(\s+use\s+)([^;]+)(;)/m', $contents)) {
            $result = preg_replace(
                '/^(\s+use\s+)([^;]+)(;)/m',
                '$1$2, '.implode(', ', $traitsToAdd).'$3',
                $contents,
                1
            );
        } else {
            // Add new trait line after class declaration
            $traitLine = 'use '.implode(', ', $traitsToAdd).';';
            $result = preg_replace(
                '/(class\s+User\s+extends\s+[^\{]+\{)/',
                "$1\n    {$traitLine}",
                $contents
            );
        }

        if ($result !== null && $result !== $contents) {
            $modified = true;

            return $result;
        }

        return $contents;
    }

    public function describe(): string
    {
        return 'Guards, providers, config, and User model traits';
    }
}
