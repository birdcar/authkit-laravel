# Implementation Spec: FGA Integration - Phase 2

**Contract**: ./contract.md
**Estimated Effort**: M

## Technical Approach

Build a `SyncsWithFGA` Eloquent model trait that maps Laravel models to FGA resources. The trait uses model observers to automatically create/update/delete FGA resources when the corresponding Eloquent model events fire. Auto-sync is configurable per-model via `$autoSyncFGA` (default true). Explicit `syncToFGA()` and `removeFromFGA()` methods are always available regardless of the auto-sync setting.

The trait requires models to define `$resourceTypeSlug` and provides sensible defaults for external ID (model primary key) and name (model `name` attribute or stringified key). Parent resolution is handled via an overridable `getFGAParent()` method.

## Feedback Strategy

**Inner-loop command**: `composer test -- --filter=SyncsWithFGA`

**Playground**: Test suite (Pest) with in-memory model stubs

**Why this approach**: This phase is model trait + observer logic. Tests with mock models and stubbed Authorization service are the tightest loop.

## File Changes

### New Files

| File Path | Purpose |
| --- | --- |
| `src/Models/Concerns/SyncsWithFGA.php` | Eloquent trait for auto-syncing models as FGA resources |
| `src/Models/Observers/FGAResourceObserver.php` | Model observer that dispatches create/update/delete to Authorization service |
| `tests/Unit/Models/SyncsWithFGATest.php` | Tests for trait behavior, observer, and manual sync methods |
| `tests/Helpers/FGATestModel.php` | Stub Eloquent model for testing the trait |

### Modified Files

| File Path | Changes |
| --- | --- |
| `src/WorkOSServiceProvider.php` | Register FGAResourceObserver for models using SyncsWithFGA (via bootable trait pattern) |

## Implementation Details

### SyncsWithFGA Trait

**Pattern to follow**: `src/Models/Concerns/HasWorkOSId.php` (for trait structure and `initialize*` hook)

**Overview**: Eloquent trait that maps a model to an FGA resource. Defines resource type, external ID, name, and parent resolution. Auto-registers observer when the trait boots.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Models\Concerns;

use WorkOS\AuthKit\Authorization\AuthorizationResource;

trait SyncsWithFGA
{
    protected bool $autoSyncFGA = true;

    public static function bootSyncsWithFGA(): void
    {
        // Register observer only if FGA feature is enabled
        if (config('workos.features.fga', false)) {
            static::observe(\WorkOS\AuthKit\Models\Observers\FGAResourceObserver::class);
        }
    }

    // Required: the resource type slug for this model
    abstract public function getResourceTypeSlug(): string;

    // External ID — defaults to model primary key
    public function getFGAExternalId(): string
    {
        return (string) $this->getKey();
    }

    // Resource name — defaults to 'name' attribute or key
    public function getFGAResourceName(): string
    {
        return $this->getAttribute('name') ?? (string) $this->getKey();
    }

    // Organization ID — required for resource creation
    abstract public function getFGAOrganizationId(): string;

    // Parent resource — return null for top-level (org is parent)
    public function getFGAParent(): ?array
    {
        return null; // ['resource_type_slug' => '...', 'external_id' => '...']
    }

    // Optional description
    public function getFGADescription(): ?string
    {
        return null;
    }

    // Manual sync
    public function syncToFGA(): AuthorizationResource
    {
        $authorization = app('workos')->authorization();
        $parent = $this->getFGAParent();

        return $authorization->createResource(
            resourceTypeSlug: $this->getResourceTypeSlug(),
            externalId: $this->getFGAExternalId(),
            organizationId: $this->getFGAOrganizationId(),
            name: $this->getFGAResourceName(),
            description: $this->getFGADescription(),
            parentResourceTypeSlug: $parent['resource_type_slug'] ?? null,
            parentResourceExternalId: $parent['external_id'] ?? null,
        );
    }

    // Manual remove
    public function removeFromFGA(bool $cascadeDelete = false): void
    {
        $authorization = app('workos')->authorization();

        $authorization->deleteResourceByExternalId(
            organizationId: $this->getFGAOrganizationId(),
            resourceTypeSlug: $this->getResourceTypeSlug(),
            externalId: $this->getFGAExternalId(),
            cascadeDelete: $cascadeDelete,
        );
    }

    // Get the FGA resource for this model
    public function getFGAResource(): AuthorizationResource
    {
        return app('workos')->authorization()->getResourceByExternalId(
            organizationId: $this->getFGAOrganizationId(),
            resourceTypeSlug: $this->getResourceTypeSlug(),
            externalId: $this->getFGAExternalId(),
        );
    }

    public function shouldAutoSyncFGA(): bool
    {
        return $this->autoSyncFGA && config('workos.features.fga', false);
    }
}
```

**Key decisions**:
- `getResourceTypeSlug()` and `getFGAOrganizationId()` are abstract — every model must define these, no guessing
- `getFGAExternalId()` defaults to primary key — the most common case
- `getFGAParent()` returns an associative array with `resource_type_slug` and `external_id` — uses external ID referencing (not internal WorkOS ID) because the parent model is also an Eloquent model likely using this trait
- `bootSyncsWithFGA()` registers the observer at the trait level — Laravel's bootable trait convention
- Observer is only registered if `features.fga` is enabled — no unnecessary overhead

**Implementation steps**:
1. Create `SyncsWithFGA` trait with abstract methods and defaults
2. Implement `syncToFGA()`, `removeFromFGA()`, `getFGAResource()` methods
3. Add `bootSyncsWithFGA()` for observer registration
4. Write tests with stub model

**Feedback loop**:
- **Playground**: Create `FGATestModel` extending a base Eloquent model with the trait
- **Experiment**: Test create → observer fires → Authorization service called; test with `$autoSyncFGA = false` → observer skips
- **Check command**: `composer test -- --filter=SyncsWithFGA`

### FGA Resource Observer

**Pattern to follow**: Standard Laravel model observer

**Overview**: Observes Eloquent model events and delegates to the Authorization service when `shouldAutoSyncFGA()` returns true.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Models\Observers;

use Illuminate\Database\Eloquent\Model;

class FGAResourceObserver
{
    public function created(Model $model): void
    {
        if (method_exists($model, 'shouldAutoSyncFGA') && $model->shouldAutoSyncFGA()) {
            $model->syncToFGA();
        }
    }

    public function updated(Model $model): void
    {
        if (method_exists($model, 'shouldAutoSyncFGA') && $model->shouldAutoSyncFGA()) {
            $authorization = app('workos')->authorization();
            $authorization->updateResourceByExternalId(
                organizationId: $model->getFGAOrganizationId(),
                resourceTypeSlug: $model->getResourceTypeSlug(),
                externalId: $model->getFGAExternalId(),
                name: $model->getFGAResourceName(),
                description: $model->getFGADescription(),
            );
        }
    }

    public function deleted(Model $model): void
    {
        if (method_exists($model, 'shouldAutoSyncFGA') && $model->shouldAutoSyncFGA()) {
            $model->removeFromFGA();
        }
    }
}
```

**Key decisions**:
- Observer checks `shouldAutoSyncFGA()` on every event — respects both the model property and config flag
- Uses `updateResourceByExternalId` on update — avoids needing to store/fetch the internal WorkOS resource ID
- `deleted` uses `removeFromFGA()` without cascade — cascade deletion should be explicit, not automatic
- `method_exists` check is defensive — observer might be attached to a model that no longer uses the trait during hot-reload scenarios

**Implementation steps**:
1. Create observer class with created/updated/deleted handlers
2. Each handler checks `shouldAutoSyncFGA()` before proceeding
3. Wire observer registration in `bootSyncsWithFGA()`

**Failure Modes**:

| Component | Failure Mode | Trigger | Impact | Mitigation |
|---|---|---|---|---|
| SyncsWithFGA::syncToFGA | API call fails during model save | Network error, bad API key | Model saved locally but not synced to FGA | Exception propagates — model save will also roll back if in transaction. Log for retry. |
| SyncsWithFGA::syncToFGA | Resource already exists | Re-saving a model that was already synced | 409 Conflict from API | Catch conflict and call update instead of create (idempotent sync) |
| Observer::created | FGA feature disabled mid-request | Config changes during request | Observer fires but shouldAutoSyncFGA returns false | No-op, correct behavior |
| Observer::deleted | FGA resource already deleted | Model deleted but FGA resource was manually removed | 404 from delete call | Catch NotFoundException silently — desired end state achieved |
| getFGAParent | Parent model not yet synced to FGA | Creating child before parent is synced | Parent resource reference invalid | Document: sync parent models first, or use `syncToFGA()` manually to control order |

## Testing Requirements

### Unit Tests

| Test File | Coverage |
| --- | --- |
| `tests/Unit/Models/SyncsWithFGATest.php` | Trait methods, observer behavior, auto-sync toggle |

**Key test cases**:
- Model with trait: `getResourceTypeSlug()` returns expected value
- Model with trait: `getFGAExternalId()` defaults to primary key
- Model with trait: `getFGAResourceName()` defaults to name attribute
- `syncToFGA()` calls `authorization()->createResource()` with correct params
- `removeFromFGA()` calls `authorization()->deleteResourceByExternalId()`
- `removeFromFGA(cascadeDelete: true)` passes cascade flag
- Observer fires on model `created` event when `shouldAutoSyncFGA()` is true
- Observer fires on model `updated` event, calling update instead of create
- Observer fires on model `deleted` event
- Observer skips when `$autoSyncFGA = false`
- Observer skips when `config('workos.features.fga')` is false
- `getFGAParent()` returns null by default (top-level resource)
- Model with custom `getFGAParent()` passes parent info to createResource

## Validation Commands

```bash
composer test -- --filter=SyncsWithFGA
composer analyse
composer format
```

## Rollout Considerations

- **Feature flag**: Inherits `config('workos.features.fga')` — observer is only registered when enabled
- **Backwards compatibility**: Fully additive — no existing traits or models affected
- **Migration note**: Existing models can adopt the trait incrementally; a bulk-sync artisan command is a future consideration

---

_This spec is ready for implementation. Follow the patterns and validate at each step._
