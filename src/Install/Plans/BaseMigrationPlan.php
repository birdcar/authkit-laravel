<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install\Plans;

abstract class BaseMigrationPlan implements MigrationPlan
{
    protected function timestamp(): string
    {
        return now()->toDateTimeString();
    }

    protected function appUrl(): string
    {
        return config('app.url', 'http://localhost');
    }

    protected function databaseMigrationSection(): string
    {
        return <<<'MARKDOWN'
### Add workos_id column
```bash
php artisan make:migration add_workos_id_to_users_table
```

Migration content:
```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('workos_id')->nullable()->unique()->after('id');
    });
}
```

### Make password nullable
```bash
php artisan make:migration make_password_nullable_on_users_table
```

Migration content:
```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('password')->nullable()->change();
    });
}
```
MARKDOWN;
    }

    protected function envVarsSection(): string
    {
        $appUrl = $this->appUrl();

        return <<<MARKDOWN
## Environment Variables

Ensure these are in your `.env`:
```
WORKOS_CLIENT_ID=client_...
WORKOS_API_KEY=sk_...
WORKOS_REDIRECT_URL={$appUrl}/auth/callback
AUTH_GUARD=workos
```
MARKDOWN;
    }

    protected function userModelSection(): string
    {
        return <<<'MARKDOWN'
```php
use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;

class User extends Authenticatable
{
    use HasWorkOSId;
    use HasWorkOSPermissions;

    protected $fillable = [
        'name',
        'email',
        'password',
        'workos_id', // Add this
    ];
}
```
MARKDOWN;
    }

    protected function enabledDisabled(bool $value): string
    {
        return $value ? 'Enabled' : 'Disabled';
    }
}
