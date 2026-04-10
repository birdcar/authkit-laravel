# Spec: API Keys, Data Integrations, Directory Sync, Settings

**Template**: ./spec-template-widget.md
**Estimated Effort**: M

## Inputs — API Keys

- Widget Group: `ApiKeys`
- Scope: TBD (not documented — query OpenAPI spec for required permissions)
- Endpoints: 4 (`organization-api-keys`, `permissions`, `{apiKeyId}`)
- Elevated access: No

## Inputs — Data Integrations

- Widget Group: `DataIntegrations`
- Scope: TBD
- Endpoints: 4 (`mine`, `installations/{installationId}`, `{slug}/authorize`, `{dataIntegrationId}/authorization-status/{state}`)
- Elevated access: No

## Inputs — Directory Sync

- Widget Group: `DirectorySync`
- Scope: TBD
- Endpoints: 2 (`directories`, `directories/{directoryId}`)
- Elevated access: No

## Inputs — Settings

- Widget Group: `Settings`
- Scope: TBD
- Endpoints: 1 (`settings`)
- Elevated access: No

## Sub-Components

| Component | Class | Blade Tag | Purpose |
|-----------|-------|-----------|---------|
| ApiKeyList | `Livewire\Widgets\ApiKeys\ApiKeyList` | `<livewire:workos-api-key-list />` | Create, view, delete organization API keys with permission scoping. |
| DataIntegrationList | `Livewire\Widgets\DataIntegrations\DataIntegrationList` | `<livewire:workos-data-integration-list />` | View installed integrations, authorize new ones, remove installations. |
| DirectoryList | `Livewire\Widgets\DirectorySync\DirectoryList` | `<livewire:workos-directory-list />` | List directories with type, name, status. Manage individual directories. |
| OrganizationSettings | `Livewire\Widgets\Settings\OrganizationSettings` | `<livewire:workos-organization-settings />` | Organization settings display and management. |

## Endpoints Used

### API Keys
| Method | Path | Used By |
|--------|------|---------|
| `POST` | `/ApiKeys/organization-api-keys` | ApiKeyList (create key) |
| `GET` | `/ApiKeys/permissions` | ApiKeyList (available permissions for key creation) |
| `DELETE` | `/ApiKeys/{apiKeyId}` | ApiKeyList (revoke key) |

### Data Integrations
| Method | Path | Used By |
|--------|------|---------|
| `GET` | `/DataIntegrations/mine` | DataIntegrationList (list installed) |
| `DELETE` | `/DataIntegrations/installations/{installationId}` | DataIntegrationList (remove) |
| `POST` | `/DataIntegrations/{slug}/authorize` | DataIntegrationList (start auth flow) |
| `GET` | `/DataIntegrations/{dataIntegrationId}/authorization-status/{state}` | DataIntegrationList (check auth status) |

### Directory Sync
| Method | Path | Used By |
|--------|------|---------|
| `GET` | `/directory-sync/directories` | DirectoryList (list all) |
| `DELETE` | `/directory-sync/directories/{directoryId}` | DirectoryList (remove) |

### Settings
| Method | Path | Used By |
|--------|------|---------|
| `GET` | `/settings` | OrganizationSettings (load) |

## Deviations from Template

- **Undocumented widget scopes**: The 4 widget groups in this phase don't have documented permission scopes in the skill references. Before implementation, query the OpenAPI spec for each endpoint's required permissions. If scopes are not documented, try the standard widget token without a specific scope and check if it works.
- **API Key secret display**: When a key is created, the `value` field is the full API key — display it ONCE with a copy button, then only show `obfuscatedValue` afterwards. This is a security-critical UX pattern.
- **Data Integration OAuth**: The `authorize` endpoint likely returns a redirect URL for OAuth — handle similar to Admin Portal links (new tab).
- **Directory types**: The directory list has a large `type` enum (Azure SCIM, BambooHR, GSuite, etc.) — display the type as a human-readable label.
- **Settings endpoint**: Only 1 endpoint — may be a simple key-value display or form. Query the OpenAPI spec for the response schema during implementation.

## Events Dispatched

| Event | Trigger | Payload |
|-------|---------|---------|
| `api-key-created` | Key created | `['id' => string]` |
| `api-key-revoked` | Key deleted | `['id' => string]` |
| `integration-installed` | Auth completed | `['slug' => string]` |
| `integration-removed` | Installation deleted | `['installationId' => string]` |
| `directory-removed` | Directory deleted | `['directoryId' => string]` |

## Phase-Specific Concerns

- This phase covers 4 smaller widgets — each is simpler than User Management or User Profile
- Scopes need discovery during implementation — may require trial and error with the WorkOS API
- API Key creation reveals a secret that cannot be retrieved later — UI must make this very clear
- Data Integration authorization is an OAuth redirect flow — may need a callback route or polling

---

_Follow `spec-template-widget.md` with the inputs above._
