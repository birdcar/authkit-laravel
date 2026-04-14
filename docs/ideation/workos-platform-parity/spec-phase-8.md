# Implementation Spec: Platform Parity - Phase 8 (Radar)

**Contract**: ./contract.md
**Template**: ./spec-template-service-wrapper.md
**Estimated Effort**: S

Read the template spec alongside this delta. This file fills in the placeholders and adds any Radar-specific details.

> **Note**: The Radar standalone API is currently in preview. Interested users must contact WorkOS support to request access. The implementation proceeds as specced but should include a clear warning in comments that preview access is required.

## Placeholder Values

| Placeholder | Value |
|---|---|
| `{ServiceName}` | `RadarService` |
| `{serviceName}` | `radar` |
| `{config_key}` | `radar` |
| `{CONFIG_KEY_UPPER}` | `RADAR` |
| `{api_endpoints}` | See endpoint table below |
| `{MiddlewareName}` | `ReportRadarAttempt` |
| `{middleware_alias}` | `workos.radar` |

## API Endpoints Wrapped

| Method | Path | Service method |
|---|---|---|
| `POST` | `/radar/attempts` | `createAttempt(array $attributes)` |
| `PATCH` | `/radar/attempts/{id}` | `updateAttempt(string $id, array $attributes)` |
| `POST` | `/radar/lists` | `addToList(array $attributes)` |
| `DELETE` | `/radar/lists` | `removeFromList(array $attributes)` |

## Service Class Details

File: `src/Services/RadarService.php`

### Method Signatures

```php
/**
 * Create a Radar attempt and get a fraud verdict.
 *
 * Required keys: ip_address, user_agent, email, auth_method, action
 * Optional keys: device_fingerprint, bot_score
 *
 * Returns: verdict (allow|block|challenge), reason, attempt_id, control?, blocklist_type?
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
public function createAttempt(array $attributes): array

/**
 * Update an existing Radar attempt (e.g. after MFA resolution).
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
public function updateAttempt(string $id, array $attributes): array

/**
 * Add an entry to a Radar block/allow list.
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
public function addToList(array $attributes): array

/**
 * Remove an entry from a Radar block/allow list.
 *
 * @param  array<string, mixed>  $attributes
 */
public function removeFromList(array $attributes): void
```

### Response Shape (createAttempt)

```
{
  verdict:       'allow'|'block'|'challenge',
  reason:        string,
  attempt_id:    string,
  control?:      string,   // which Radar control triggered (bot_detection, brute_force_attack, etc.)
  blocklist_type?: string,
}
```

### HTTP Implementation

Same pattern as `VaultService` — use Laravel's `Http` facade with `Authorization: Bearer {api_key}` and the `workos.widgets.base_url` base URL. The Radar API lives at the same `api.workos.com` base.

### auth_method Enum Values

WorkOS accepts: `Password`, `Passkey`, `Authenticator`, `SMS_OTP`, `Email_OTP`, `Social`, `SSO`, `Other`

### action Enum Values

WorkOS accepts: `sign-in`, `sign-up` (and legacy variants `login`, `signup`, `sign_in`, `sign_up`)

Normalise to `sign-in` / `sign-up` in the service if callers pass legacy variants.

## Middleware Details

File: `src/Http/Middleware/ReportRadarAttempt.php`

**Purpose**: On authentication routes, automatically create a Radar attempt and inject the verdict into the request so downstream controllers can gate on it. Designed for use on custom auth flows — AuthKit-managed flows handle Radar natively.

```php
public function handle(Request $request, Closure $next, string $action = 'sign-in'): Response
{
    if (! config('workos.features.radar', false)) {
        return $next($request);
    }

    $verdict = app(RadarService::class)->createAttempt([
        'ip_address' => $request->ip(),
        'user_agent' => $request->userAgent() ?? '',
        'email'      => $request->input('email', ''),
        'auth_method'=> 'Password',
        'action'     => $action,
    ]);

    // Block immediately on 'block' verdict; pass through on 'allow'/'challenge'
    if (($verdict['verdict'] ?? 'allow') === 'block') {
        return response()->json(['message' => 'Access denied.'], 403);
    }

    // Inject verdict into request for downstream use
    $request->merge(['_radar_verdict' => $verdict]);

    return $next($request);
}
```

**Route usage**:
```php
Route::post('/login', LoginController::class)->middleware('workos.radar:sign-in');
Route::post('/register', RegisterController::class)->middleware('workos.radar:sign-up');
```

**Key decision**: The middleware silently passes through when radar is disabled (feature flag false), so routes don't need to be conditionally decorated. When the verdict is `challenge`, the middleware passes through and injects the verdict — the controller decides whether to trigger MFA.

## Config

```php
// workos.php — features array
'radar' => env('WORKOS_FEATURE_RADAR', false),
```

## Testing Requirements

Test files:
- `tests/Unit/RadarServiceTest.php`
- `tests/Feature/ReportRadarAttemptTest.php`

**Unit test key cases** (in addition to template requirements):

- `createAttempt()` sends `POST /radar/attempts` with correct body
- `updateAttempt()` sends `PATCH /radar/attempts/{id}` with correct body
- `addToList()` sends `POST /radar/lists`
- `removeFromList()` sends `DELETE /radar/lists` and returns void
- Response with `verdict: 'block'` is returned as-is
- `RuntimeException` thrown on API 4xx

**Feature test key cases**:

- Middleware passes through when `workos.features.radar` is false
- Middleware returns 403 when verdict is `block`
- Middleware merges `_radar_verdict` into request when verdict is `allow`
- Middleware merges `_radar_verdict` into request when verdict is `challenge`
- `action` parameter is forwarded to `createAttempt()`

## Validation Commands

```bash
composer analyse
composer test
```
