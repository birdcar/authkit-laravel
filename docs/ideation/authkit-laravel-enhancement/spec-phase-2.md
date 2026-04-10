# Implementation Spec: AuthKit Laravel Enhancement - Phase 2

**PRD**: ./prd-phase-2.md
**Estimated Effort**: L (Large)

## Technical Approach

This phase scaffolds a complete Laravel 12 application in the `workbench/` directory. The approach is to use Laravel's native installer to create a fresh app, then customize it for Flux Pro and AuthKit integration.

Key technical decisions:
1. Use Laravel 12 with SQLite for zero-config development
2. Install Livewire 3.x and Flux Pro for the component library
3. Configure Tailwind CSS v4.x as required by Flux v2.0
4. Use path repository to link to the parent AuthKit package
5. Create base layouts with Flux components

The workbench pattern is standard for Laravel package development - it allows testing the package in a real Laravel application context.

## File Changes

### New Files

| File Path | Purpose |
|-----------|---------|
| `workbench/` | Complete Laravel 12 application |
| `workbench/app/Models/User.php` | User model with HasWorkOSId trait |
| `workbench/app/Models/Organization.php` | Organization model with HasWorkOSId trait |
| `workbench/app/Models/Todo.php` | Todo model (for Phase 4) |
| `workbench/database/migrations/*` | All required migrations |
| `workbench/database/seeders/DatabaseSeeder.php` | Demo data seeder |
| `workbench/resources/views/components/layouts/app.blade.php` | Main app layout |
| `workbench/resources/views/components/layouts/guest.blade.php` | Guest layout |
| `workbench/resources/css/app.css` | Tailwind + Flux CSS |
| `workbench/.env.example` | Environment template |

### Modified Files

| File Path | Changes |
|-----------|---------|
| `composer.json` | Add serve, fresh, test:example scripts |
| `.gitattributes` | Ensure workbench is excluded from dist |

### Deleted Files

None.

## Implementation Details

### Create Laravel Application

**Overview**: Scaffold fresh Laravel 12 app in workbench directory.

```bash
# From repository root
composer create-project laravel/laravel workbench --prefer-dist

# Enter workbench
cd workbench

# Remove default sqlite database (we'll configure fresh)
rm database/database.sqlite 2>/dev/null || true
```

**Key decisions**:
- Use Laravel 12 (current stable)
- Keep default directory structure
- Remove Breeze/Jetstream - we're building custom auth

**Implementation steps**:
1. Run `composer create-project laravel/laravel workbench`
2. Enter workbench directory
3. Configure for development

### Configure Path Repository

**Overview**: Link workbench to parent AuthKit package for development.

**workbench/composer.json additions**:
```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../",
            "options": {
                "symlink": true
            }
        }
    ],
    "require": {
        "workos/authkit-laravel": "@dev"
    }
}
```

**Implementation steps**:
1. Edit `workbench/composer.json`
2. Add repositories section
3. Run `composer require workos/authkit-laravel:@dev`
4. Run `php artisan workos:install`

### Install Livewire and Flux Pro

**Overview**: Install frontend dependencies for Flux Pro components.

```bash
cd workbench

# Install Livewire and Flux Pro
composer require livewire/livewire livewire/flux

# Install Node dependencies
npm install -D tailwindcss@latest @tailwindcss/vite

# Or with bun
bun add -d tailwindcss@latest @tailwindcss/vite
```

**Key decisions**:
- Use Flux Pro (user has license)
- Use Tailwind CSS v4.x (required by Flux v2.0)
- Use Vite for asset compilation

**Implementation steps**:
1. Install Composer packages
2. Install Node/Bun packages
3. Configure Tailwind CSS

### Configure Tailwind CSS

**workbench/resources/css/app.css**:
```css
@import 'tailwindcss';
@import '../../vendor/livewire/flux/dist/flux.css';

@custom-variant dark (&:where(.dark, .dark *));

/* Custom app styles */
@layer base {
    body {
        @apply antialiased;
    }
}
```

**workbench/vite.config.js**:
```javascript
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
```

### Configure SQLite Database

**workbench/.env.example** (database section):
```env
DB_CONNECTION=sqlite
# DB_DATABASE is automatically set to database/database.sqlite
```

**Implementation steps**:
1. Update `.env.example` with SQLite config
2. Copy to `.env` during setup
3. Touch `database/database.sqlite`

### Database Migrations

**workbench/database/migrations/2024_01_01_000001_create_users_table.php**:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('workos_id')->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('avatar_url')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
```

**workbench/database/migrations/2024_01_01_000002_create_organizations_table.php**:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('workos_id')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('domains')->nullable(); // JSON array
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
```

**workbench/database/migrations/2024_01_01_000003_create_organization_user_table.php**:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('member');
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_user');
    }
};
```

**workbench/database/migrations/2024_01_01_000004_create_todos_table.php**:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->boolean('completed')->default(false);
            $table->timestamps();

            $table->index(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todos');
    }
};
```

### Models

**workbench/app/Models/User.php**:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;

class User extends Authenticatable
{
    use HasFactory, HasWorkOSId, HasWorkOSPermissions, Notifiable;

    protected $fillable = [
        'workos_id',
        'name',
        'email',
        'avatar_url',
    ];

    protected $hidden = [
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class);
    }

    public static function findOrCreateByWorkOS(array $data): static
    {
        return static::updateOrCreate(
            ['workos_id' => $data['id']],
            [
                'email' => $data['email'] ?? null,
                'name' => trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')),
                'avatar_url' => $data['profile_picture_url'] ?? null,
            ]
        );
    }
}
```

**workbench/app/Models/Organization.php**:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;

class Organization extends Model
{
    use HasFactory, HasWorkOSId;

    protected $fillable = [
        'workos_id',
        'name',
        'slug',
        'domains',
    ];

    protected function casts(): array
    {
        return [
            'domains' => 'array',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class);
    }

    public static function findOrCreateByWorkOS(array $data): static
    {
        return static::updateOrCreate(
            ['workos_id' => $data['id']],
            [
                'name' => $data['name'],
                'slug' => Str::slug($data['name']),
                'domains' => $data['domains'] ?? [],
            ]
        );
    }
}
```

**workbench/app/Models/Todo.php**:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use WorkOS\AuthKit\Audit\Contracts\Auditable;
use WorkOS\AuthKit\Audit\Concerns\HasAuditTrail;

class Todo extends Model implements Auditable
{
    use HasFactory, HasAuditTrail;

    protected $fillable = [
        'user_id',
        'organization_id',
        'title',
        'completed',
    ];

    protected function casts(): array
    {
        return [
            'completed' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function getAuditName(): string
    {
        return 'todo';
    }

    public function getAuditId(): string
    {
        return (string) $this->id;
    }
}
```

### Base Layouts

**workbench/resources/views/components/layouts/app.blade.php**:
```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name', 'Todo App') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxStyles
</head>
<body class="min-h-screen bg-white dark:bg-zinc-800">
    @impersonating
        <flux:callout variant="warning" icon="eye" class="rounded-none">
            You are currently impersonating this user.
            <x-slot name="actions">
                <flux:button size="sm" href="{{ route('logout') }}">End Session</flux:button>
            </x-slot>
        </flux:callout>
    @endimpersonating

    <flux:sidebar sticky stashable class="bg-zinc-50 dark:bg-zinc-900 border-r border-zinc-200 dark:border-zinc-700">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

        <flux:brand href="{{ route('dashboard') }}" logo="https://workos.imgix.net/images/workos-logo.svg" name="Todo App" class="px-2 dark:hidden" />
        <flux:brand href="{{ route('dashboard') }}" logo="https://workos.imgix.net/images/workos-logo-white.svg" name="Todo App" class="px-2 hidden dark:flex" />

        <flux:navlist variant="outline">
            <flux:navlist.item icon="home" href="{{ route('dashboard') }}" :current="request()->routeIs('dashboard')">Dashboard</flux:navlist.item>
            <flux:navlist.item icon="check-circle" href="{{ route('todos.index') }}" :current="request()->routeIs('todos.*')">Todos</flux:navlist.item>
        </flux:navlist>

        <flux:spacer />

        {{-- Organization Switcher - Added in Phase 3 --}}
        <livewire:organization-switcher />

        <flux:navlist variant="outline">
            <flux:navlist.item icon="cog-6-tooth" href="{{ route('organizations.settings') }}" :current="request()->routeIs('organizations.settings')">Settings</flux:navlist.item>
        </flux:navlist>

        <flux:dropdown position="top" align="start" class="max-lg:hidden">
            <flux:profile avatar="{{ auth()->user()->avatar_url }}" name="{{ auth()->user()->name }}" />

            <flux:menu>
                <flux:menu.item icon="arrow-right-start-on-rectangle" href="{{ route('logout') }}">Logout</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </flux:sidebar>

    <flux:header class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <flux:spacer />

        <flux:dropdown position="top" align="end">
            <flux:profile avatar="{{ auth()->user()->avatar_url }}" />

            <flux:menu>
                <flux:menu.item icon="arrow-right-start-on-rectangle" href="{{ route('logout') }}">Logout</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </flux:header>

    <flux:main>
        {{ $slot }}
    </flux:main>

    @fluxScripts
</body>
</html>
```

**workbench/resources/views/components/layouts/guest.blade.php**:
```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name', 'Todo App') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxStyles
</head>
<body class="min-h-screen bg-white dark:bg-zinc-800">
    <div class="flex min-h-screen flex-col items-center justify-center">
        {{ $slot }}
    </div>

    @fluxScripts
</body>
</html>
```

### Composer Scripts

**Root composer.json additions**:
```json
{
    "scripts": {
        "serve": [
            "Composer\\Config::disableProcessTimeout",
            "cd workbench && php artisan serve"
        ],
        "fresh": [
            "cd workbench && php artisan migrate:fresh --seed"
        ],
        "test:example": [
            "cd workbench && ./vendor/bin/pest"
        ]
    }
}
```

### Environment Setup

**workbench/.env.example**:
```env
APP_NAME="Todo App"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000

LOG_CHANNEL=stack
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

DB_CONNECTION=sqlite

BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SESSION_LIFETIME=120

# WorkOS Configuration
WORKOS_API_KEY=sk_test_your_api_key
WORKOS_CLIENT_ID=client_your_client_id
WORKOS_REDIRECT_URI=http://localhost:8000/auth/callback
WORKOS_WEBHOOK_SECRET=
```

## Data Model

### Schema Summary

```
users
├── id (bigint, PK)
├── workos_id (string, unique)
├── name (string)
├── email (string, unique)
├── avatar_url (string, nullable)
├── email_verified_at (timestamp, nullable)
├── remember_token (string, nullable)
├── created_at (timestamp)
└── updated_at (timestamp)

organizations
├── id (bigint, PK)
├── workos_id (string, unique)
├── name (string)
├── slug (string, unique)
├── domains (text/json, nullable)
├── created_at (timestamp)
└── updated_at (timestamp)

organization_user (pivot)
├── id (bigint, PK)
├── organization_id (bigint, FK)
├── user_id (bigint, FK)
├── role (string, default: 'member')
├── created_at (timestamp)
└── updated_at (timestamp)

todos
├── id (bigint, PK)
├── user_id (bigint, FK)
├── organization_id (bigint, FK)
├── title (string)
├── completed (boolean, default: false)
├── created_at (timestamp)
└── updated_at (timestamp)
```

## Testing Requirements

### Manual Testing

- [ ] Run `composer serve` - app starts at localhost:8000
- [ ] Access app - Flux components render correctly
- [ ] Run `composer fresh` - database is reset
- [ ] Run `npm run build` (or `bun run build`) - assets compile
- [ ] Check workbench excluded from dist (`git archive`)

## Error Handling

| Error Scenario | Handling Strategy |
|----------------|-------------------|
| Missing WorkOS credentials | Show error on auth attempt |
| SQLite file missing | Auto-create on first migration |
| Asset build fails | Clear error message from Vite |
| Package symlink fails | Check path repository config |

## Validation Commands

```bash
# From repository root

# Test serve command
composer serve
# Visit http://localhost:8000 (should show Laravel welcome or error about routes)

# Test fresh command
composer fresh

# Build assets (from workbench)
cd workbench && npm install && npm run build

# Verify workbench excluded
git archive HEAD --prefix=test/ -o test.tar.gz
tar -tzf test.tar.gz | grep workbench  # Should show nothing
rm test.tar.gz
```

## Rollout Considerations

- **Feature flag**: None needed
- **Monitoring**: Check app starts without errors
- **Alerting**: N/A for local development
- **Rollback plan**: Delete workbench directory and re-scaffold

## Open Items

- [ ] Decide on dark mode default (currently dark)
- [ ] Confirm Flux Pro license activation method

---

*This spec is ready for implementation. Follow the patterns and validate at each step.*
