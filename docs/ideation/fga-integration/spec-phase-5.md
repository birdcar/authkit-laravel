# Implementation Spec: FGA Integration - Phase 5

**Contract**: ./contract.md
**Estimated Effort**: M

## Technical Approach

Build a workbench demo page that demonstrates FGA capabilities: resource hierarchy visualization, role assignments on resources, and resource-scoped permission checks. This follows the existing workbench app patterns (Livewire components, Flux UI, Tailwind).

The demo creates a sample hierarchy (Workspace → Project → App), lets the authenticated user assign roles on resources, and shows live check results. This serves as both a developer reference and a manual test environment.

## Feedback Strategy

**Inner-loop command**: `cd workbench && php artisan serve`

**Playground**: Dev server (workbench app)

**Why this approach**: This phase is UI — visual verification via the dev server is the only meaningful feedback loop.

## File Changes

### New Files

| File Path | Purpose |
| --- | --- |
| `workbench/app/Livewire/FGADemo.php` | Main Livewire component for FGA demo page |
| `workbench/app/Models/Project.php` | Demo model using SyncsWithFGA trait |
| `workbench/app/Models/Workspace.php` | Demo model using SyncsWithFGA trait (parent resource) |
| `workbench/resources/views/livewire/fga-demo.blade.php` | Blade template for FGA demo |
| `workbench/database/migrations/xxxx_create_workspaces_table.php` | Workspaces table for demo |
| `workbench/database/migrations/xxxx_create_projects_table.php` | Projects table for demo |

### Modified Files

| File Path | Changes |
| --- | --- |
| `workbench/routes/web.php` | Add `/fga` route pointing to FGADemo component |
| `workbench/resources/views/components/layouts/app.blade.php` | Add FGA nav link |
| `workbench/config/workos.php` | Enable `features.fga` for workbench |

## Implementation Details

### Demo Models

**Pattern to follow**: Existing workbench User model

**Overview**: Two simple Eloquent models that demonstrate the SyncsWithFGA trait with a parent-child hierarchy.

```php
// workbench/app/Models/Workspace.php
class Workspace extends Model
{
    use SyncsWithFGA;

    protected $fillable = ['name', 'organization_id'];

    public function getResourceTypeSlug(): string
    {
        return 'workspace';
    }

    public function getFGAOrganizationId(): string
    {
        return $this->organization_id;
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}

// workbench/app/Models/Project.php
class Project extends Model
{
    use SyncsWithFGA;

    protected $fillable = ['name', 'workspace_id', 'organization_id'];

    public function getResourceTypeSlug(): string
    {
        return 'project';
    }

    public function getFGAOrganizationId(): string
    {
        return $this->organization_id;
    }

    public function getFGAParent(): ?array
    {
        return [
            'resource_type_slug' => 'workspace',
            'external_id' => (string) $this->workspace_id,
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
```

**Implementation steps**:
1. Create migrations for workspaces and projects tables
2. Create Workspace and Project models with SyncsWithFGA
3. Verify observer fires on create/update/delete

### FGA Demo Livewire Component

**Pattern to follow**: Existing workbench Livewire components (e.g., dashboard page)

**Overview**: A single-page demo showing resource hierarchy, CRUD operations, role assignment, and permission checking.

The page has three sections:
1. **Resource Hierarchy** — shows workspaces and nested projects, with create/delete buttons
2. **Role Assignments** — assign roles to the current user on selected resources
3. **Permission Check** — select a resource and permission, see the live check result

```php
class FGADemo extends Component
{
    public ?string $selectedResourceType = null;
    public ?string $selectedExternalId = null;
    public ?string $checkPermission = null;
    public ?bool $checkResult = null;

    public string $newWorkspaceName = '';
    public string $newProjectName = '';
    public ?int $newProjectWorkspaceId = null;

    public ?string $assignRoleSlug = null;

    public function createWorkspace(): void { /* ... */ }
    public function createProject(): void { /* ... */ }
    public function deleteWorkspace(int $id): void { /* ... */ }
    public function deleteProject(int $id): void { /* ... */ }
    public function checkAccess(): void { /* ... */ }
    public function assignRole(): void { /* ... */ }
    public function removeAssignment(string $assignmentId): void { /* ... */ }

    public function render(): View
    {
        return view('livewire.fga-demo', [
            'workspaces' => Workspace::with('projects')
                ->where('organization_id', WorkOS::session()?->organizationId)
                ->get(),
            'assignments' => $this->loadAssignments(),
        ]);
    }
}
```

**Key decisions**:
- Single Livewire component for the entire demo — keeps it simple, this is a reference implementation not a product
- Uses the current user's session organizationId for all operations
- Shows real API responses (success/failure) so developers can see the actual behavior
- No persistence of FGA state locally — always reads from WorkOS API

**Implementation steps**:
1. Create the Livewire component class with resource CRUD actions
2. Add role assignment and permission check actions
3. Build the blade template with three sections
4. Add route and navigation link
5. Enable FGA feature flag in workbench config

### Blade Template

**Overview**: Clean demo page using Flux UI components consistent with existing workbench styling.

Layout:
- Header: "Fine-Grained Authorization" with brief explanation
- Left panel: Resource hierarchy tree (workspaces → projects) with create/delete
- Right panel top: Permission check — dropdown for resource, input for permission slug, check button, result badge
- Right panel bottom: Role assignments — list current assignments, assign new role form

**Implementation steps**:
1. Build the template using Flux UI card/button/input components
2. Wire Livewire actions to buttons
3. Show success/error feedback with Flux toast/badge components
4. Style the resource hierarchy as an indented tree

**Failure Modes**:

| Component | Failure Mode | Trigger | Impact | Mitigation |
|---|---|---|---|---|
| FGADemo | No organization in session | User logged in without org | Empty state, no resources | Show "Select an organization to use FGA" message |
| FGADemo | FGA not enabled in WorkOS environment | Free tier or FGA not provisioned | API errors on all calls | Catch exceptions, show "FGA is not enabled for your WorkOS environment" |
| FGADemo | Resource type not configured in Dashboard | Workspaces/projects not defined as resource types | Create resource fails | Show clear error: "Configure resource types in your WorkOS Dashboard first" |
| createWorkspace | Model observer fails to sync | API error during FGA resource creation | Workspace saved locally but not in FGA | Show error toast, offer manual sync button |

## Testing Requirements

### Manual Testing

- [ ] Navigate to `/fga` in workbench — page loads without errors
- [ ] Create a workspace — appears in hierarchy, FGA resource created (check WorkOS Dashboard)
- [ ] Create a project under workspace — appears nested, FGA resource has correct parent
- [ ] Run permission check — shows authorized/unauthorized correctly
- [ ] Assign role on resource — assignment appears in list
- [ ] Remove assignment — disappears from list
- [ ] Delete project — removed from hierarchy and FGA
- [ ] Delete workspace with projects — cascade behavior works as expected

## Validation Commands

```bash
# Start workbench server
cd workbench && php artisan serve

# Run all package tests (ensure nothing broke)
composer test
composer analyse
composer format
```

## Rollout Considerations

- **Prerequisite**: FGA must be enabled on the WorkOS environment being used for the workbench
- **Resource types**: Developers must create `workspace` and `project` resource types in their WorkOS Dashboard before the demo works
- **Documentation**: Add a note to the workbench README explaining FGA demo prerequisites

---

_This spec is ready for implementation. Follow the patterns and validate at each step._
