# Spec: Admin Portal Widgets (SSO Connection + Domain Verification)

**Template**: ./spec-template-widget.md
**Estimated Effort**: S

## Inputs — SSO Connection

- Widget Group: `AdminPortal` (sub: `SsoConnection`)
- Scope: `widgets:sso:manage`
- Endpoints: 2 (`sso-connections`, `generate-link`)
- Elevated access: No

## Inputs — Domain Verification

- Widget Group: `AdminPortal` (sub: `DomainVerification`)
- Scope: `widgets:domain-verification:manage`
- Endpoints: 4 (`organization-domains`, `organization-domains/{domainId}`, `organization-domains/{domainId}/reverify`, `generate-link`)
- Elevated access: No

## Sub-Components

| Component | Class | Blade Tag | Purpose |
|-----------|-------|-----------|---------|
| SsoConnectionList | `Livewire\Widgets\AdminPortal\SsoConnectionList` | `<livewire:workos-sso-connection-list />` | SSO connection name + status, "Manage" link to Admin Portal. |
| DomainList | `Livewire\Widgets\AdminPortal\DomainList` | `<livewire:workos-domain-list />` | Domain list with status, reverify, remove, "Add domain" link. |
| **AdminPortal** | `Livewire\Widgets\AdminPortal\AdminPortal` | `<livewire:workos-admin-portal />` | Composed parent: SSO + Domains in sections. |

## Endpoints Used

| Method | Path | Used By |
|--------|------|---------|
| `GET` | `/admin-portal/sso-connections` | SsoConnectionList |
| `POST` | `/admin-portal/generate-link` | SsoConnectionList (manage link), DomainList (add domain link) |
| `GET` | `/admin-portal/organization-domains` | DomainList |
| `DELETE` | `/admin-portal/organization-domains/{domainId}` | DomainList (remove) |
| `POST` | `/admin-portal/organization-domains/{domainId}/reverify` | DomainList (reverify) |

## Deviations from Template

- **Two scopes in one phase**: SsoConnectionList and DomainList use different widget scopes. Each sub-component overrides `widgetScope()` independently.
- **Admin Portal links**: Both widgets use `POST /admin-portal/generate-link` with an `intent` parameter to get a redirect URL. The link opens in a new tab — use `target="_blank"` with `rel="noopener"`.
- **Simple UI**: These are mostly read-only displays with action links. No complex forms or pagination.
- `generate-link` returns a temporary URL — don't cache it. Generate fresh on each click.

## Events Dispatched

| Event | Trigger | Payload |
|-------|---------|---------|
| `domain-removed` | Domain deleted | `['domainId' => string]` |
| `domain-reverification-started` | Reverify triggered | `['domainId' => string]` |

---

_Follow `spec-template-widget.md` with the inputs above._
