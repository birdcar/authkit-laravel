# Context Map: livewire-workos-widgets

**Phase**: 5
**Scout Confidence**: 88/100
**Verdict**: GO

## Dimensions

| Dimension | Score | Notes |
|---|---|---|
| Scope clarity | 17/20 | Four standalone PHP classes, four blade views, CSS additions, service provider registrations. No composed parents needed. Ambiguity: widget scopes are TBD. |
| Pattern familiarity | 20/20 | All prior phase patterns fully understood. Phase 5 components are simpler versions of existing patterns. |
| Dependency awareness | 18/20 | Service provider is only consumer. Blast radius limited. |
| Edge case coverage | 16/20 | API Key secret display, OAuth redirect URL field name unknown, scope strings undocumented. |
| Test strategy | 17/20 | Pest infra exists. No Livewire tests yet (not a dev dep). |

## Key Patterns

- `src/Livewire/Widgets/AdminPortal/SsoConnectionList.php` — Most recent sub-component pattern to follow
- `src/Livewire/Widgets/AdminPortal/DomainList.php` — DELETE + POST action pattern with error bag checking
- `src/Livewire/Concerns/WithWidgetApi.php` — widgetGet/widgetPost/widgetDelete, base URL prefixed with `/_widgets`
- `src/Livewire/Concerns/WithWidgetToken.php` — `getWidgetToken(scope)` calls WorkOS Widgets API
- `resources/views/livewire/widgets/admin-portal/sso-connection-list.blade.php` — Blade pattern with JS event dispatch for external links
- `resources/css/widgets.css` — Append new classes after existing content
- `src/WorkOSServiceProvider.php` — Add imports and registrations in `configureLivewireWidgets()`

## Conventions

- **Naming**: `ApiKeys/ApiKeyList.php`, view `api-keys/api-key-list.blade.php`, tag `workos-api-key-list`
- **Imports**: `WithWidgetApi` first, then `WithWidgetTheme`. Never import `WithWidgetToken`.
- **Error handling**: `resetErrorBag('widget')` before mutations, check `! $this->getErrorBag()->has('widget')` after
- **Scopes** (educated guesses): `widgets:api-keys:manage`, `widgets:data-integrations:manage`, `widgets:directory-sync:manage`, `widgets:settings:read`

## Risks

- Widget scopes are TBD — use educated guesses based on pattern
- API Key secret shown once only — need `$newKeyValue` state with copy+dismiss UX
- OAuth redirect URL field name unknown — use defensive `$result['link'] ?? $result['url']`
- No composed parents for Phase 5 — each widget is standalone
