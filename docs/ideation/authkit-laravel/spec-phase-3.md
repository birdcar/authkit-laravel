# Implementation Spec: AuthKit Laravel - Phase 3

**PRD**: ./prd-phase-3.md
**Estimated Effort**: L (Large)

## Technical Approach

Phase 3 implements team/organization management following Jetstream's patterns. The `HasOrganization` trait provides relationships and switching logic, while organization context is stored in the session alongside user authentication.

Organization switching updates session context without re-authentication. The sync command uses WorkOS User Management API pagination to import users and memberships efficiently.

## File Changes

### New Files

| File Path | Purpose |
|-----------|---------|
| `src/Models/Concerns/HasOrganization.php` | Organization relationship trait |
| `src/Models/Organization.php` | Base organization model |
| `src/Http/Controllers/OrganizationController.php` | Switching, invitations |
| `src/Http/Middleware/CheckOrganization.php` | Org membership/role check |
| `src/Events/OrganizationSwitched.php` | Org switch event |
| `src/Events/InvitationSent.php` | Invitation event |
| `src/Events/InvitationRevoked.php` | Revoke event |
| `src/Commands/SyncUsersCommand.php` | User sync command |
| `database/migrations/create_organizations_table.php` | Organizations table |
| `database/migrations/create_organization_user_table.php` | Pivot table |
| `routes/organizations.php` | Organization routes |
| `tests/Unit/HasOrganizationTest.php` | Trait tests |
| `tests/Feature/OrganizationSwitchTest.php` | Switching tests |
| `tests/Feature/SyncCommandTest.php` | Sync command tests |

### Modified Files

| File Path | Changes |
|-----------|---------|
| `src/WorkOSServiceProvider.php` | Register org routes, middleware |
| `src/Auth/SessionManager.php` | Add org context methods |
| `config/workos.php` | Add org-related config |

## Implementation Details

### HasOrganization Trait

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use WorkOS\AuthKit\Models\Organization;

trait HasOrganization
{
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(
            config('workos.organization_model', Organization::class),
            'organization_user',
            'user_id',
            'organization_id'
        )->withPivot('role')->withTimestamps();
    }

    public function currentOrganization(): ?Organization
    {
        $orgId = $this->currentOrganizationId();
        if (!$orgId) {
            return null;
        }
        return $this->organizations()->where('workos_id', $orgId)->first();
    }

    public function switchOrganization(string $organizationId): bool
    {
        if (!$this->belongsToOrganization($organizationId)) {
            return false;
        }

        app(SessionManager::class)->setOrganizationId($organizationId);

        event(new OrganizationSwitched($this, $organizationId));

        return true;
    }

    public function belongsToOrganization(string $organizationId): bool
    {
        return $this->organizations()
            ->where('workos_id', $organizationId)
            ->exists();
    }

    public function organizationRole(string $organizationId): ?string
    {
        $org = $this->organizations()
            ->where('workos_id', $organizationId)
            ->first();

        return $org?->pivot?->role;
    }

    public function hasOrganizationRole(string $organizationId, string $role): bool
    {
        return $this->organizationRole($organizationId) === $role;
    }

    public function hasOrganizationPermission(string $organizationId, string $permission): bool
    {
        // Check from session permissions filtered by org context
        $session = $this->getWorkOSSession();
        if ($session?->organizationId !== $organizationId) {
            return false;
        }
        return in_array($permission, $session->permissions ?? [], true);
    }
}
```

### Organization Model

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Organization extends Model
{
    protected $fillable = [
        'workos_id',
        'name',
        'slug',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            config('workos.user_model'),
            'organization_user',
            'organization_id',
            'user_id'
        )->withPivot('role')->withTimestamps();
    }

    public static function findByWorkOSId(string $workosId): ?static
    {
        return static::where('workos_id', $workosId)->first();
    }

    public static function findOrCreateByWorkOS(array $data): static
    {
        return static::firstOrCreate(
            ['workos_id' => $data['id']],
            [
                'name' => $data['name'],
                'slug' => $data['slug'] ?? null,
            ]
        );
    }

    public function syncFromWorkOS(): void
    {
        $orgs = new \WorkOS\Organizations();
        $data = $orgs->getOrganization($this->workos_id);

        $this->update([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? null,
        ]);
    }
}
```

### OrganizationController

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use WorkOS\AuthKit\Events\InvitationSent;
use WorkOS\UserManagement;

class OrganizationController
{
    public function switch(Request $request): RedirectResponse
    {
        $request->validate([
            'organization_id' => 'required|string',
        ]);

        $user = $request->user();
        $orgId = $request->input('organization_id');

        if (!$user->switchOrganization($orgId)) {
            return back()->withErrors(['organization' => 'You do not belong to this organization.']);
        }

        return redirect()->intended('/');
    }

    public function invite(Request $request, string $organizationId): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
            'role' => 'nullable|string',
        ]);

        $userManagement = new UserManagement();

        $invitation = $userManagement->sendInvitation(
            email: $request->input('email'),
            organizationId: $organizationId,
            role: $request->input('role'),
        );

        event(new InvitationSent($organizationId, $request->input('email'), $invitation));

        return back()->with('success', 'Invitation sent.');
    }
}
```

### SyncUsersCommand

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Commands;

use Illuminate\Console\Command;
use WorkOS\UserManagement;

class SyncUsersCommand extends Command
{
    protected $signature = 'workos:sync-users
        {--organization= : Sync only users from this organization}
        {--limit=100 : Number of users per page}';

    protected $description = 'Sync users from WorkOS';

    public function handle(): int
    {
        $this->info('Syncing users from WorkOS...');

        $userManagement = new UserManagement();
        $userModel = config('workos.user_model');
        $organizationId = $this->option('organization');
        $limit = (int) $this->option('limit');

        $cursor = null;
        $synced = 0;

        do {
            $response = $userManagement->listUsers(
                organizationId: $organizationId,
                limit: $limit,
                after: $cursor,
            );

            foreach ($response['data'] as $workosUser) {
                $userModel::findOrCreateByWorkOS($workosUser);
                $synced++;
            }

            $cursor = $response['list_metadata']['after'] ?? null;

            $this->info("Synced {$synced} users...");

        } while ($cursor !== null);

        $this->info("✓ Synced {$synced} users successfully.");

        return self::SUCCESS;
    }
}
```

## Data Model

### Schema Changes

```php
// create_organizations_table.php
Schema::create('organizations', function (Blueprint $table) {
    $table->id();
    $table->string('workos_id')->unique();
    $table->string('name');
    $table->string('slug')->nullable();
    $table->timestamps();
});

// create_organization_user_table.php
Schema::create('organization_user', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
    $table->string('role')->nullable();
    $table->timestamps();

    $table->unique(['user_id', 'organization_id']);
});
```

## API Design

### Routes

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/organizations/switch` | Switch active organization |
| `POST` | `/organizations/{org}/invitations` | Send invitation |
| `GET` | `/organizations/{org}/invitations` | List invitations |
| `DELETE` | `/organizations/{org}/invitations/{id}` | Revoke invitation |

## Testing Requirements

**Key test cases**:
- `switchOrganization()` updates session context
- `belongsToOrganization()` checks membership correctly
- `hasOrganizationRole()` checks pivot role
- Middleware blocks user not in organization
- Sync command handles pagination
- Invitations sent via WorkOS API

## Validation Commands

```bash
./vendor/bin/pest tests/Unit/HasOrganizationTest.php
./vendor/bin/pest tests/Feature/OrganizationSwitchTest.php
./vendor/bin/pest tests/Feature/SyncCommandTest.php
```

---

*This spec is ready for implementation.*
