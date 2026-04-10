# Implementation Spec: Livewire WorkOS Widgets — Phase 1: Infrastructure

**Contract**: ./contract.md
**Estimated Effort**: M

## Technical Approach

Build the foundation that all widget components depend on: token management trait, HTTP client for WorkOS widget APIs, base CSS with theming variables, service provider registration with soft dependency guard, and the config/publishing infrastructure. No visible UI in this phase — just the plumbing.

The token management trait wraps `WorkOS\Widgets::getToken()` and caches tokens per-scope for their 1-hour lifetime. The HTTP client provides a thin wrapper around Guzzle (already a transitive dependency via workos-php) for calling `/_widgets/*` endpoints with proper authorization headers. The base CSS file defines the same `--woswidgets-*` custom properties and `.woswidgets-*` base class styles as the official `@workos-inc/widgets` package.

## Feedback Strategy

**Inner-loop command**: `composer test && composer analyse`

**Playground**: Test suite — traits and services are tested via unit tests.

**Why this approach**: Infrastructure is all server-side PHP with no UI. Tests are the fastest feedback loop.

## File Changes

### New Files

| File Path | Purpose |
|-----------|---------|
| `src/Livewire/Concerns/WithWidgetToken.php` | Trait: token acquisition and caching per widget scope |
| `src/Livewire/Concerns/WithWidgetApi.php` | Trait: HTTP client for `/_widgets/*` endpoints with auth headers |
| `src/Livewire/Concerns/WithElevatedAccess.php` | Trait: elevated token flow for sensitive User Profile operations |
| `src/Livewire/Concerns/WithWidgetTheme.php` | Trait: theme prop handling, CSS variable generation |
| `resources/css/widgets.css` | Base CSS: `--woswidgets-*` variables, `.woswidgets-*` class definitions |
| `tests/Unit/WithWidgetTokenTest.php` | Token trait tests |
| `tests/Unit/WithWidgetApiTest.php` | API client trait tests |

### Modified Files

| File Path | Changes |
|-----------|---------|
| `src/WorkOSServiceProvider.php` | Add `configureLivewireWidgets()` with `class_exists` guard, view loading, CSS publishing |
| `config/workos.php` | Add `widgets` feature flag and `widgets.base_url` config |
| `composer.json` | Add `livewire/livewire` to `suggest`, add `resources/css` to autoload |

## Implementation Details

### 1. WithWidgetToken Trait

**Pattern to follow**: `src/Testing/Concerns/InteractsWithWorkOS.php` (existing trait pattern)

**Overview**: Acquires and caches widget tokens per scope. Uses the WorkOS PHP SDK's `Widgets::getToken()`.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Concerns;

use WorkOS\Resource\WidgetScope;
use WorkOS\Widgets;

trait WithWidgetToken
{
    private ?string $widgetToken = null;
    private ?int $tokenExpiresAt = null;

    protected function getWidgetToken(string $scope): string
    {
        if ($this->widgetToken && $this->tokenExpiresAt > time()) {
            return $this->widgetToken;
        }

        $session = workos()->validSession();
        $widgets = new Widgets();
        $response = $widgets->getToken(
            organization_id: $session->organizationId,
            user_id: $session->userId,
            scopes: [$scope],
        );

        $this->widgetToken = $response->token;
        $this->tokenExpiresAt = time() + 3500; // ~58 min, before 1hr expiry

        return $this->widgetToken;
    }

    protected function clearWidgetToken(): void
    {
        $this->widgetToken = null;
        $this->tokenExpiresAt = null;
    }
}
```

**Key decisions**:
- Cache token for ~58 minutes (just under the 1-hour expiry) to avoid unnecessary API calls
- Uses the `workos()` helper to get session — consistent with package patterns
- `clearWidgetToken()` for manual invalidation when org context changes

**Implementation steps**:
1. Create `src/Livewire/Concerns/WithWidgetToken.php`
2. Add unit test verifying token acquisition and caching behavior

**Feedback loop**:
- **Playground**: Create `tests/Unit/WithWidgetTokenTest.php` with mock of `Widgets` class
- **Check command**: `vendor/bin/pest tests/Unit/WithWidgetTokenTest.php`

### 2. WithWidgetApi Trait

**Overview**: HTTP client for WorkOS widget API endpoints. Wraps Guzzle with authorization header injection.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Concerns;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

trait WithWidgetApi
{
    private ?Client $widgetClient = null;

    protected function widgetGet(string $path, array $query = []): array
    {
        return $this->widgetRequest('GET', $path, ['query' => $query]);
    }

    protected function widgetPost(string $path, array $data = []): array
    {
        return $this->widgetRequest('POST', $path, ['json' => $data]);
    }

    protected function widgetDelete(string $path): array
    {
        return $this->widgetRequest('DELETE', $path);
    }

    private function widgetRequest(string $method, string $path, array $options = []): array
    {
        $client = $this->getWidgetClient();
        $token = $this->getWidgetToken($this->widgetScope());

        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
        ]);

        try {
            $response = $client->request($method, "/_widgets{$path}", $options);
            return json_decode($response->getBody()->getContents(), true);
        } catch (ClientException $e) {
            $body = json_decode($e->getResponse()->getBody()->getContents(), true);
            $this->addError('widget', $body['message'] ?? 'Widget API error');
            return [];
        }
    }

    private function getWidgetClient(): Client
    {
        return $this->widgetClient ??= new Client([
            'base_uri' => config('workos.widgets.base_url', 'https://api.workos.com'),
        ]);
    }

    abstract protected function widgetScope(): string;
}
```

**Key decisions**:
- Each component defines its own `widgetScope()` — enforces correct scope per widget
- Error responses populate Livewire's `$errors` bag via `addError()` — standard Livewire pattern
- JSON decode to arrays (not objects) — consistent with Laravel conventions
- Base URL configurable for testing/staging environments

**Implementation steps**:
1. Create `src/Livewire/Concerns/WithWidgetApi.php`
2. Add unit test with mocked Guzzle client

### 3. WithElevatedAccess Trait

**Overview**: Handles the elevated token flow for sensitive User Profile operations (password, TOTP, passkeys).

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Concerns;

trait WithElevatedAccess
{
    private ?string $elevatedToken = null;
    private ?int $elevatedExpiresAt = null;

    protected function getElevatedToken(string $verificationMethod): ?string
    {
        if ($this->elevatedToken && $this->elevatedExpiresAt > time()) {
            return $this->elevatedToken;
        }

        $result = $this->widgetPost('/UserProfile/verify', [
            'verificationMethod' => $verificationMethod,
        ]);

        if (isset($result['elevatedAccessToken'])) {
            $this->elevatedToken = $result['elevatedAccessToken'];
            $this->elevatedExpiresAt = time() + 540; // ~9 min, before 10-min expiry
            return $this->elevatedToken;
        }

        return null;
    }

    protected function withElevatedAccess(array $headers = []): array
    {
        if ($this->elevatedToken) {
            $headers['x-elevated-access-token'] = $this->elevatedToken;
        }

        return $headers;
    }

    protected function clearElevatedToken(): void
    {
        $this->elevatedToken = null;
        $this->elevatedExpiresAt = null;
    }
}
```

**Key decisions**:
- Cache elevated token for ~9 minutes (just under 10-minute expiry)
- Components that need elevation call `getElevatedToken()` before sensitive operations
- `withElevatedAccess()` returns headers array that gets merged into API requests

### 4. WithWidgetTheme Trait

**Overview**: Handles theme props and generates inline CSS variable declarations for the component root.

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Concerns;

trait WithWidgetTheme
{
    public ?string $accentColor = null;
    public ?string $borderColor = null;
    public ?string $backgroundColor = null;
    public ?string $foregroundColor = null;
    public string $appearance = 'light';

    protected function themeStyles(): string
    {
        $vars = [];

        if ($this->accentColor) {
            $vars[] = "--woswidgets-accent-color: {$this->accentColor}";
        }
        if ($this->borderColor) {
            $vars[] = "--woswidgets-border-color: {$this->borderColor}";
        }
        if ($this->backgroundColor) {
            $vars[] = "--woswidgets-background-color: {$this->backgroundColor}";
        }
        if ($this->foregroundColor) {
            $vars[] = "--woswidgets-foreground-color: {$this->foregroundColor}";
        }

        return implode('; ', $vars);
    }

    protected function themeClass(): string
    {
        return "woswidgets-root woswidgets-{$this->appearance}";
    }
}
```

**Key decisions**:
- Props match the 4 official CSS variables exactly
- `appearance` prop controls light/dark via class
- `themeStyles()` returns inline CSS string for the root element's `style` attribute
- `themeClass()` returns the root CSS class matching official widget DOM

### 5. Base CSS File

**Pattern to follow**: Official `@workos-inc/widgets/dist/css/base.css`

**Overview**: Ship a CSS file with default `--woswidgets-*` variable values and `.woswidgets-*` class definitions matching the official React widget styles.

```css
/* resources/css/widgets.css */
:root {
    --woswidgets-accent-color: hsl(212 100% 50% / 1);
    --woswidgets-border-color: light-dark(hsl(0 0% 46%), hsl(0 0% 52%));
    --woswidgets-background-color: light-dark(hsl(0 0% 100%), hsl(0 0% 0%));
    --woswidgets-foreground-color: light-dark(hsl(0 0% 0%), hsl(0 0% 100%));
}

.woswidgets-root { /* container */ }
.woswidgets-card { /* card container */ }
.woswidgets-card-list-item { /* list items */ }
.woswidgets-text-field { /* input fields */ }
.woswidgets-dialog { /* modal dialogs */ }
.woswidgets-status { /* status indicators */ }
.woswidgets-marker { /* badges/tags */ }
/* ... additional classes built out during widget phases */
```

**Implementation steps**:
1. Create `resources/css/widgets.css` with variable defaults
2. Add structural class definitions as each widget phase implements them
3. This file grows with each phase — Phase 1 ships the variables and container classes only

### 6. Service Provider Registration

**Pattern to follow**: `src/WorkOSServiceProvider.php` existing `configureGuard()`, `configureMiddleware()` patterns

**Overview**: Register Livewire components conditionally and set up view/CSS publishing.

Add to `WorkOSServiceProvider::boot()`:
```php
$this->configureLivewireWidgets();
```

```php
protected function configureLivewireWidgets(): void
{
    if (! class_exists(\Livewire\Component::class)) {
        return;
    }

    if (! config('workos.features.widgets', true)) {
        return;
    }

    $this->loadViewsFrom(__DIR__.'/../resources/views', 'workos');

    $this->publishes([
        __DIR__.'/../resources/views/livewire/widgets' => resource_path('views/vendor/workos/livewire/widgets'),
    ], 'workos-widget-views');

    $this->publishes([
        __DIR__.'/../resources/css/widgets.css' => public_path('vendor/workos/widgets.css'),
    ], 'workos-widget-styles');

    // Component registration happens here as widgets are added in phases 2-5
}
```

### 7. Config Updates

Add to `config/workos.php` features array:
```php
'widgets' => env('WORKOS_FEATURE_WIDGETS', true),
```

Add new widgets section:
```php
'widgets' => [
    'base_url' => env('WORKOS_BASE_API_URL', 'https://api.workos.com'),
],
```

## Testing Requirements

### Unit Tests

| Test File | Coverage |
|-----------|---------|
| `tests/Unit/WithWidgetTokenTest.php` | Token acquisition, caching, expiry, cache clearing |
| `tests/Unit/WithWidgetApiTest.php` | GET/POST/DELETE calls, auth header injection, error handling |

**Key test cases**:
- Token is cached and reused within expiry window
- Expired token triggers re-acquisition
- API errors populate Livewire error bag
- `class_exists` guard prevents registration without Livewire

## Validation Commands

```bash
# Run package tests (should still be 335+ passing)
composer test

# Static analysis
composer analyse

# Code style
composer format:test
```

## Failure Modes

| Component | Failure Mode | Trigger | Impact | Mitigation |
|-----------|-------------|---------|--------|------------|
| WithWidgetToken | Token acquisition fails | Invalid API key, insufficient permissions | Widget renders error state | Catch exception, populate error bag |
| WithWidgetToken | Stale cached token | Clock skew | 401 from API | Clear cache on 401, retry once |
| WithWidgetApi | WorkOS API unreachable | Network issue | Widget shows error | Guzzle timeout config, error state in view |
| Service Provider | Livewire not installed | User doesn't use Livewire | No impact | `class_exists` guard — silent skip |

---

_This spec is ready for implementation. Follow the patterns and validate at each step._
