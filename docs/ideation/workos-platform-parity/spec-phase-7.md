# Implementation Spec: Platform Parity - Phase 7 (Vault)

**Contract**: ./contract.md
**Template**: ./spec-template-service-wrapper.md
**Estimated Effort**: M

Read the template spec alongside this delta. This file fills in the placeholders and adds any Vault-specific details.

## Placeholder Values

| Placeholder | Value |
|---|---|
| `{ServiceName}` | `VaultService` |
| `{serviceName}` | `vault` |
| `{config_key}` | `vault` |
| `{CONFIG_KEY_UPPER}` | `VAULT` |
| `{api_endpoints}` | See endpoint table below |
| `{MiddlewareName}` | *(none)* |

## API Endpoints Wrapped

| Method | Path | Service method |
|---|---|---|
| `POST` | `/vault/objects` | `store(string $name, string $value, array $context = [])` |
| `GET` | `/vault/objects/{id}` | `get(string $id)` |
| `GET` | `/vault/objects/by-name/{name}` | `getByName(string $name)` |
| `PATCH` | `/vault/objects/{id}` | `update(string $id, string $value)` |
| `DELETE` | `/vault/objects/{id}` | `delete(string $id)` |
| `GET` | `/vault/objects` | `list(int $limit = 10, ?string $after = null)` |
| `GET` | `/vault/objects/{id}/versions` | `versions(string $id)` |
| `POST` | `/vault/keys/encrypt` | `encrypt(string $plaintext, array $context = [])` |
| `POST` | `/vault/keys/decrypt` | `decrypt(string $ciphertext, array $context = [])` |

No middleware — Vault is a server-side data service, not a request-time concern.

## Service Class Details

File: `src/Services/VaultService.php`

### Method Signatures

```php
/**
 * Store an encrypted secret by name.
 *
 * @param  array<string, mixed>  $context
 * @return array<string, mixed>
 */
public function store(string $name, string $value, array $context = []): array

/**
 * Retrieve and decrypt a secret by its object ID.
 *
 * @return array<string, mixed>
 */
public function get(string $id): array

/**
 * Retrieve and decrypt a secret by its name.
 *
 * @return array<string, mixed>
 */
public function getByName(string $name): array

/**
 * Update the value of an existing secret.
 *
 * @return array<string, mixed>
 */
public function update(string $id, string $value): array

/**
 * Delete a secret.
 */
public function delete(string $id): void

/**
 * List vault objects with pagination.
 *
 * @return array<string, mixed>
 */
public function list(int $limit = 10, ?string $after = null): array

/**
 * List version history for a vault object.
 *
 * @return array<string, mixed>
 */
public function versions(string $id): array

/**
 * Encrypt a plaintext value without storing it.
 *
 * @param  array<string, mixed>  $context
 * @return array<string, mixed>
 */
public function encrypt(string $plaintext, array $context = []): array

/**
 * Decrypt a ciphertext value.
 *
 * @param  array<string, mixed>  $context
 * @return array<string, mixed>
 */
public function decrypt(string $ciphertext, array $context = []): array
```

### Response Shape (for `store`, `get`, `getByName`)

The WorkOS Vault API returns the full object on `GET` (including the decrypted `value`). On `POST`/`PATCH`, it returns metadata only (no `value` field). Service methods return the raw API array. Callers access `$result['value']` for the decrypted value.

```
{
  id:        string,
  name:      string,
  value:     string,   // present on GET only
  metadata:  {
    id:          string,
    keyId:       string,
    updatedAt:   string,
    updatedBy:   { id: string, name: string },
    versionId:   string,
  },
  context:   array<string, mixed>,
}
```

### HTTP Implementation

The WorkOS PHP SDK does not currently expose a Vault client. The service uses Laravel's `Http` facade directly:

```php
use Illuminate\Support\Facades\Http;

private function baseUrl(): string
{
    return rtrim(config('workos.widgets.base_url', 'https://api.workos.com'), '/');
}

private function headers(): array
{
    return [
        'Authorization' => 'Bearer '.config('workos.api_key'),
        'Content-Type'  => 'application/json',
    ];
}
```

All methods call `Http::withHeaders($this->headers())->{verb}(...)`. Throw `\RuntimeException` on non-2xx responses, passing the response body for context.

### Facade Usage Example

```php
// Store a secret
WorkOS::vault()->store('stripe_api_key', $value, ['organizationId' => $orgId]);

// Retrieve a secret (returns decrypted value)
$result = WorkOS::vault()->getByName('stripe_api_key');
$apiKey = $result['value'];

// Encrypt without storing
$encrypted = WorkOS::vault()->encrypt($sensitiveData)['ciphertext'];
```

## Config

```php
// workos.php — features array
'vault' => env('WORKOS_FEATURE_VAULT', false),
```

## Testing Requirements

Test file: `tests/Unit/VaultServiceTest.php`

**Key test cases** (in addition to template requirements):

- `store()` sends `POST /vault/objects` with `name`, `value`, and `context` in body
- `get()` sends `GET /vault/objects/{id}` and returns array with `value` key
- `getByName()` sends `GET /vault/objects/by-name/{name}`
- `update()` sends `PATCH /vault/objects/{id}` with `value` in body
- `delete()` sends `DELETE /vault/objects/{id}` and returns void
- `encrypt()` sends `POST /vault/keys/encrypt` with `plaintext` and `context`
- `decrypt()` sends `POST /vault/keys/decrypt` with `ciphertext` and `context`
- `RuntimeException` is thrown when API returns 4xx
- `WorkOS::vault()` throws `RuntimeException` when `workos.features.vault` is false

## Validation Commands

```bash
composer analyse
composer test
```
