# Implementation Spec: Platform Parity - Phase 9 (Pipes)

**Contract**: ./contract.md
**Template**: ./spec-template-service-wrapper.md
**Estimated Effort**: S

Read the template spec alongside this delta. This file fills in the placeholders and adds any Pipes-specific details.

## Placeholder Values

| Placeholder | Value |
|---|---|
| `{ServiceName}` | `PipesService` |
| `{serviceName}` | `pipes` |
| `{config_key}` | `pipes` |
| `{CONFIG_KEY_UPPER}` | `PIPES` |
| `{api_endpoints}` | See endpoint table below |
| `{MiddlewareName}` | *(none)* |

## API Endpoints Wrapped

| Method | Path | Service method |
|---|---|---|
| `GET` | `/data-integrations` | `listProviders()` |
| `POST` | `/data-integrations/{slug}/authorize` | `getAuthorizationUrl(string $slug, string $userId, ...)` |
| `GET` | `/user_management/users/{userId}/connected_accounts/{slug}` | `getConnectedAccount(string $userId, string $slug, ...)` |
| `DELETE` | `/user_management/users/{userId}/connected_accounts/{slug}` | `deleteConnectedAccount(string $userId, string $slug, ...)` |
| `GET` | `/user_management/users/{userId}/connected_accounts/{slug}/access_token` | `getAccessToken(string $userId, string $slug, ...)` |

## Service Class Details

File: `src/Services/PipesService.php`

### Method Signatures

```php
/**
 * List available OAuth providers (integrations).
 *
 * @return array<string, mixed>
 */
public function listProviders(): array

/**
 * Generate an OAuth authorization URL to connect a user's account.
 *
 * @param  string  $slug         Provider identifier (e.g. 'github', 'google-sheets')
 * @param  string  $userId       WorkOS user ID
 * @param  string|null  $returnTo URL to redirect to after OAuth completes
 * @param  string|null  $organizationId
 * @return string The authorization URL to redirect the user to
 */
public function getAuthorizationUrl(
    string $slug,
    string $userId,
    ?string $returnTo = null,
    ?string $organizationId = null,
): string

/**
 * Retrieve a connected account for a user and provider.
 *
 * @return array<string, mixed>
 */
public function getConnectedAccount(
    string $userId,
    string $slug,
    ?string $organizationId = null,
): array

/**
 * Delete (disconnect) a connected account.
 */
public function deleteConnectedAccount(
    string $userId,
    string $slug,
    ?string $organizationId = null,
): void

/**
 * Retrieve or refresh the access token for a connected account.
 *
 * @return array<string, mixed>  Contains 'access_token' and optional 'expires_at'
 */
public function getAccessToken(
    string $userId,
    string $slug,
    ?string $organizationId = null,
): array
```

### Response Shape (getConnectedAccount)

```
{
  object:          'connected_account',
  id:              string,
  user_id:         string,
  organization_id: string|null,
  scopes:          string[],
  state:           'connected'|'disconnected',
  created_at:      string,
  updated_at:      string,
}
```

### getAuthorizationUrl Return Value

`getAuthorizationUrl` returns a plain `string` (the URL), not an array — it unwraps `$response['url']` before returning. This matches how `WorkOS::loginUrl()` returns a string directly rather than a raw API response.

### HTTP Implementation

Same pattern as `VaultService` — Laravel's `Http` facade with `Authorization: Bearer {api_key}`. The Pipes endpoints live on `api.workos.com` (same base URL).

**Key decision on `returnTo`**: If `$returnTo` is null, default to `config('workos.routes.home', '/')`. This keeps the controller layer clean — callers don't need to pass the redirect URL explicitly in most cases.

### Facade Usage Example

```php
// Get authorization URL and redirect user
$url = WorkOS::pipes()->getAuthorizationUrl('github', auth()->id());
return redirect($url);

// After OAuth callback, get the connected account
$account = WorkOS::pipes()->getConnectedAccount(auth()->id(), 'github');

// Get an access token for an API call
$token = WorkOS::pipes()->getAccessToken(auth()->id(), 'github')['access_token'];
```

### No Controller Provided

Pipes does not include a built-in callback controller — the OAuth callback is handled by the third-party provider redirecting to the user's own application route. The `returnTo` URL should point to a controller in the host application that calls `getConnectedAccount()` to confirm the connection. Document this pattern in the workbench app.

## Config

```php
// workos.php — features array
'pipes' => env('WORKOS_FEATURE_PIPES', false),
```

## Testing Requirements

Test file: `tests/Unit/PipesServiceTest.php`

**Key test cases** (in addition to template requirements):

- `listProviders()` sends `GET /data-integrations` and returns array
- `getAuthorizationUrl()` sends `POST /data-integrations/{slug}/authorize` with `user_id`, `return_to`; returns the string URL from `$response['url']`
- `getAuthorizationUrl()` defaults `returnTo` to `config('workos.routes.home')` when null
- `getConnectedAccount()` sends `GET /user_management/users/{userId}/connected_accounts/{slug}`
- `deleteConnectedAccount()` sends `DELETE` and returns void
- `getAccessToken()` returns array with `access_token` key
- Optional `organizationId` is included in request body when provided, omitted when null
- `WorkOS::pipes()` throws `RuntimeException` when `workos.features.pipes` is false

## Validation Commands

```bash
composer analyse
composer test
```
