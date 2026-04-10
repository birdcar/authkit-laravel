# Implementation Spec: AuthKit Laravel - Phase 4

**PRD**: ./prd-phase-4.md
**Estimated Effort**: M (Medium)

## Technical Approach

Phase 4 integrates WorkOS Audit Logs using a service wrapper that handles event construction and API calls. The middleware provides automatic route-level auditing. All audit calls are no-ops when the feature flag is disabled.

Test assertions are added to `WorkOSFake` to capture and verify audit events without hitting the real API.

## File Changes

### New Files

| File Path | Purpose |
|-----------|---------|
| `src/Audit/AuditLogger.php` | Service for sending audit events |
| `src/Audit/AuditMiddleware.php` | Automatic route auditing |
| `src/Audit/Contracts/Auditable.php` | Interface for auditable models |
| `src/Audit/Concerns/HasAuditTrail.php` | Default implementation |
| `tests/Unit/AuditLoggerTest.php` | Logger unit tests |
| `tests/Unit/AuditMiddlewareTest.php` | Middleware tests |
| `tests/Feature/AuditIntegrationTest.php` | Full audit flow |

### Modified Files

| File Path | Changes |
|-----------|---------|
| `src/WorkOS.php` | Add `audit()` convenience method |
| `src/Testing/WorkOSFake.php` | Add audit capture and assertions |
| `src/WorkOSServiceProvider.php` | Register audit middleware alias |

## Implementation Details

### AuditLogger

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Audit;

use WorkOS\AuditLogs;

class AuditLogger
{
    public function __construct(
        private readonly AuditLogs $auditLogs,
    ) {}

    public function log(
        string $action,
        array $targets = [],
        ?string $actorId = null,
        array $metadata = [],
    ): void {
        if (!config('workos.features.audit_logs', false)) {
            return;
        }

        $user = auth()->user();

        $event = [
            'action' => [
                'type' => $action,
                'name' => $this->humanize($action),
            ],
            'actor' => [
                'type' => 'user',
                'id' => $actorId ?? $user?->getWorkOSId(),
                'name' => $user?->name,
            ],
            'targets' => $this->normalizeTargets($targets),
            'context' => [
                'location' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ],
            'metadata' => $metadata,
            'occurred_at' => now()->toIso8601String(),
        ];

        // Add organization if in org context
        if ($orgId = app(SessionManager::class)->getOrganizationId()) {
            $event['organization_id'] = $orgId;
        }

        try {
            $this->auditLogs->createEvent($event);
        } catch (\Exception $e) {
            report($e); // Log but don't crash
        }
    }

    private function normalizeTargets(array $targets): array
    {
        return array_map(function ($target) {
            if ($target instanceof Contracts\Auditable) {
                return $target->toAuditTarget();
            }
            return [
                'type' => $target['type'] ?? 'resource',
                'id' => (string) ($target['id'] ?? ''),
                'name' => $target['name'] ?? null,
                'metadata' => $target['metadata'] ?? null,
            ];
        }, $targets);
    }

    private function humanize(string $action): string
    {
        return ucfirst(str_replace(['.', '_'], ' ', $action));
    }
}
```

### AuditMiddleware

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Audit;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditMiddleware
{
    public function __construct(
        private readonly AuditLogger $logger,
    ) {}

    public function handle(Request $request, Closure $next, ?string $action = null): Response
    {
        $response = $next($request);

        // Only log successful requests
        if ($response->isSuccessful()) {
            $this->logger->log(
                action: $action ?? $this->inferAction($request),
                targets: $this->extractTargets($request),
                metadata: [
                    'route' => $request->route()?->getName(),
                    'method' => $request->method(),
                    'path' => $request->path(),
                ]
            );
        }

        return $response;
    }

    private function inferAction(Request $request): string
    {
        $resource = $request->route()?->getName() ?? 'resource';

        return match ($request->method()) {
            'GET' => "{$resource}.read",
            'POST' => "{$resource}.create",
            'PUT', 'PATCH' => "{$resource}.update",
            'DELETE' => "{$resource}.delete",
            default => "{$resource}.access",
        };
    }

    private function extractTargets(Request $request): array
    {
        $targets = [];

        foreach ($request->route()?->parameters() ?? [] as $key => $value) {
            if (is_object($value) && $value instanceof Contracts\Auditable) {
                $targets[] = $value;
            } elseif (is_string($value) || is_numeric($value)) {
                $targets[] = ['type' => $key, 'id' => (string) $value];
            }
        }

        return $targets;
    }
}
```

### Auditable Contract

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Audit\Contracts;

interface Auditable
{
    public function toAuditTarget(): array;
}
```

### HasAuditTrail Trait

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Audit\Concerns;

trait HasAuditTrail
{
    public function toAuditTarget(): array
    {
        return [
            'type' => $this->getAuditType(),
            'id' => (string) $this->getKey(),
            'name' => $this->getAuditName(),
        ];
    }

    protected function getAuditType(): string
    {
        return strtolower(class_basename($this));
    }

    protected function getAuditName(): ?string
    {
        return $this->name ?? $this->title ?? null;
    }
}
```

### WorkOSFake Audit Assertions

Add to `src/Testing/WorkOSFake.php`:

```php
private array $auditedEvents = [];

public function audit(string $action, array $targets = [], array $metadata = []): void
{
    $this->auditedEvents[] = compact('action', 'targets', 'metadata');
}

public function assertAudited(string $action, ?callable $callback = null): static
{
    $matching = array_filter(
        $this->auditedEvents,
        fn ($e) => $e['action'] === $action
    );

    Assert::assertNotEmpty($matching, "Expected audit event [{$action}] was not logged.");

    if ($callback) {
        foreach ($matching as $event) {
            if ($callback($event)) {
                return $this;
            }
        }
        Assert::fail("Audit event [{$action}] logged but callback returned false.");
    }

    return $this;
}

public function assertNotAudited(string $action): static
{
    $matching = array_filter(
        $this->auditedEvents,
        fn ($e) => $e['action'] === $action
    );

    Assert::assertEmpty($matching, "Unexpected audit event [{$action}] was logged.");

    return $this;
}

public function assertAuditedCount(int $count): static
{
    Assert::assertCount($count, $this->auditedEvents);
    return $this;
}
```

### WorkOS Service audit() Method

Add to `src/WorkOS.php`:

```php
public function audit(string $action, array $targets = [], array $metadata = []): void
{
    app(AuditLogger::class)->log($action, $targets, metadata: $metadata);
}
```

## Testing Requirements

**Key test cases**:
- `AuditLogger::log()` sends event when feature enabled
- `AuditLogger::log()` is no-op when feature disabled
- API failures are caught and logged, not thrown
- Middleware logs on successful response
- Middleware does not log on failed response
- `Auditable` models convert correctly
- `assertAudited()` passes when event logged
- `assertNotAudited()` passes when event not logged

## Validation Commands

```bash
./vendor/bin/pest tests/Unit/AuditLoggerTest.php
./vendor/bin/pest tests/Unit/AuditMiddlewareTest.php
./vendor/bin/pest tests/Feature/AuditIntegrationTest.php
```

---

*This spec is ready for implementation.*
