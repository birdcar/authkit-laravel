# Implementation Spec: Platform Parity - Phase 10 (Domain Verification)

**Contract**: ./contract.md
**Template**: ./spec-template-service-wrapper.md
**Estimated Effort**: S

Read the template spec alongside this delta. This file fills in the placeholders and adds Domain Verification-specific details, including typed webhook events for `organization_domain.*` events.

## Placeholder Values

| Placeholder | Value |
|---|---|
| `{ServiceName}` | `DomainService` |
| `{serviceName}` | `domains` |
| `{config_key}` | `domain_verification` |
| `{CONFIG_KEY_UPPER}` | `DOMAIN_VERIFICATION` |
| `{api_endpoints}` | See endpoint table below |
| `{MiddlewareName}` | *(none)* |

## API Endpoints Wrapped

| Method | Path | Service method |
|---|---|---|
| `POST` | `/organization_domains` | `create(string $organizationId, string $domain)` |
| `GET` | `/organization_domains/{id}` | `get(string $id)` |
| `POST` | `/organization_domains/{id}/verify` | `verify(string $id)` |
| `DELETE` | `/organization_domains/{id}` | `delete(string $id)` |

## Service Class Details

File: `src/Services/DomainService.php`

### Method Signatures

```php
/**
 * Create a new organization domain (starts in 'pending' state).
 *
 * @return array<string, mixed>  Domain object with verification_token and verification_prefix
 */
public function create(string $organizationId, string $domain): array

/**
 * Retrieve an organization domain by ID.
 *
 * @return array<string, mixed>
 */
public function get(string $id): array

/**
 * Trigger DNS verification for a pending domain.
 *
 * @return array<string, mixed>  Updated domain object with current state
 */
public function verify(string $id): array

/**
 * Delete an organization domain.
 */
public function delete(string $id): void
```

### Response Shape

Domain object returned by all non-delete methods:

```
{
  id:                    string,
  organization_id:       string,
  domain:                string,
  state:                 'pending'|'verified'|'failed',
  verification_prefix:   string,
  verification_token:    string,
  verification_strategy: 'dns'|'manual',
  created_at:            string,
  updated_at:            string,
}
```

### HTTP Implementation

Same pattern as `VaultService` — Laravel's `Http` facade with `Authorization: Bearer {api_key}` against `api.workos.com`.

### Facade Usage Example

```php
// Create a domain and get DNS verification instructions
$domain = WorkOS::domains()->create($orgId, 'example.com');
// $domain['verification_prefix'] + '.' + $domain['verification_token'] = DNS TXT record value

// Poll or trigger verification after DNS propagation
$domain = WorkOS::domains()->verify($domainId);
// $domain['state'] === 'verified' once DNS check passes
```

## Typed Webhook Events

Domain verification has five webhook event types. Add typed event classes following the same pattern as Phase 6 (using `HasEventData`).

### New Event Files

| File Path | Event Type |
|---|---|
| `src/Events/Sync/WorkOSOrganizationDomainCreated.php` | `organization_domain.created` |
| `src/Events/Sync/WorkOSOrganizationDomainUpdated.php` | `organization_domain.updated` |
| `src/Events/Sync/WorkOSOrganizationDomainDeleted.php` | `organization_domain.deleted` |
| `src/Events/Sync/WorkOSOrganizationDomainVerified.php` | `organization_domain.verified` |
| `src/Events/Sync/WorkOSOrganizationDomainVerificationFailed.php` | `organization_domain.verification_failed` |

### Event Accessors

All five events share these accessors (from the domain object payload):

```php
public function domainId(): string        // $this->data['id']
public function organizationId(): string  // $this->data['organization_id']
public function domain(): string          // $this->data['domain']
public function state(): string           // $this->data['state']
```

`WorkOSOrganizationDomainVerificationFailed` adds:

```php
public function reason(): ?string  // $this->data['reason'] ?? null
```

### WebhookController EVENT_MAP Additions

```php
'organization_domain.created'             => WorkOSOrganizationDomainCreated::class,
'organization_domain.updated'             => WorkOSOrganizationDomainUpdated::class,
'organization_domain.deleted'             => WorkOSOrganizationDomainDeleted::class,
'organization_domain.verified'            => WorkOSOrganizationDomainVerified::class,
'organization_domain.verification_failed' => WorkOSOrganizationDomainVerificationFailed::class,
```

### EventRouting CATEGORY_MAP Addition

`organization_domain.*` events share the `organization_domain.` prefix, which is not currently in `CATEGORY_MAP`. Add it with longest-prefix ordering (before `organization.`):

```php
'organization_domain.' => 'organization_domain',
```

Add a corresponding config key in `workos.events.routing.categories`:

```php
'organization_domain' => env('WORKOS_SYNC_ORGANIZATION_DOMAIN', 'webhooks'),
```

No default listeners are registered for domain events — domain state changes are informational and application-specific. Developers register their own listeners via `workos.sync.listeners` or standard Laravel event registration.

## Config

```php
// workos.php — features array
'domain_verification' => env('WORKOS_FEATURE_DOMAIN_VERIFICATION', false),
```

## Testing Requirements

Test files:
- `tests/Unit/DomainServiceTest.php`
- `tests/Unit/Sync/WorkOSOrganizationDomainEventsTest.php`

**Service unit test key cases** (in addition to template requirements):

- `create()` sends `POST /organization_domains` with `organization_id` and `domain` in body
- `get()` sends `GET /organization_domains/{id}` and returns domain object
- `verify()` sends `POST /organization_domains/{id}/verify` and returns updated domain
- `delete()` sends `DELETE /organization_domains/{id}` and returns void
- `RuntimeException` thrown on API 4xx
- `WorkOS::domains()` throws `RuntimeException` when `workos.features.domain_verification` is false

**Domain event test key cases**:

- Each event class exposes typed accessors from the payload
- `WorkOSOrganizationDomainVerificationFailed::reason()` returns null when `reason` key is absent
- `WebhookController::EVENT_MAP` contains all five `organization_domain.*` keys
- `EventRouting` routes `organization_domain.*` events via the `organization_domain` category

## Validation Commands

```bash
composer analyse
composer test
```
