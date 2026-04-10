# Livewire WorkOS Widgets Contract

**Created**: 2026-04-08
**Confidence Score**: 96/100
**Status**: Approved
**Supersedes**: None

## Problem Statement

WorkOS provides API-driven widgets (User Management, User Profile, SSO Configuration, Domain Verification, API Keys, Data Integrations, Directory Sync, Settings) via React components backed by REST endpoints. Laravel developers who don't use React — the majority of the Livewire/Blade ecosystem — cannot use these widgets without building the entire UI themselves.

The `birdcar/authkit-laravel` package already provides authentication, session management, and audit logging, but has no UI layer. Adding Livewire components that wrap every WorkOS widget API gives Laravel developers drop-in, themeable UI components with full CSS parity to the official React widgets.

## Goals

1. Ship Livewire components for all 8 WorkOS widget groups, matching the official React widgets' functionality
2. Achieve full CSS parity — same `--woswidgets-*` CSS custom properties, same `.woswidgets-*` class names — so existing WorkOS widget CSS customizations work unchanged
3. Provide both granular sub-components (UserProfileInfo, SecuritySettings, SessionManagement) and pre-composed parent components (UserProfile) for each widget group
4. Livewire remains a soft dependency — the package works without it, zero overhead for non-Livewire users
5. Ship publishable Blade views so developers can customize rendering while keeping the server-side logic intact

## Success Criteria

- [ ] All 8 widget groups have working Livewire components that call the correct WorkOS `/_widgets/*` API endpoints
- [ ] Widget tokens are obtained via `WorkOS\Widgets::getToken()` with correct scopes per widget
- [ ] HTML output uses `.woswidgets-*` class names matching the official React widget DOM structure
- [ ] `--woswidgets-accent-color`, `--woswidgets-border-color`, `--woswidgets-background-color`, `--woswidgets-foreground-color` CSS variables control theming
- [ ] User Profile elevated access flow (password, TOTP, passkeys) works via `POST /_widgets/UserProfile/verify`
- [ ] Components render loading, error, and empty states explicitly
- [ ] `class_exists(\Livewire\Component::class)` guard prevents registration when Livewire is not installed
- [ ] Publishable views: `php artisan vendor:publish --tag=workos-widget-views`
- [ ] Publishable CSS: `php artisan vendor:publish --tag=workos-widget-styles`
- [ ] Existing package tests (335) continue passing — no regressions

## Scope Boundaries

### In Scope

**Widget Groups (8):**

1. **User Management** — Members table with pagination, search, role filtering, role editing, user deletion, invite flow (9 endpoints)
2. **User Profile** — Profile info, authentication info, password management, TOTP, passkeys, session management (15 endpoints, some elevated)
3. **Admin Portal SSO Connection** — SSO connection list with status, manage link to Admin Portal (2 endpoints)
4. **Admin Portal Domain Verification** — Domain list with status, reverify, remove, add domain link (4 endpoints)
5. **API Keys** — Organization API key management with permissions (4 endpoints)
6. **Data Integrations** — Integration installations, authorization flows (4 endpoints)
7. **Directory Sync** — Directory list with management (2 endpoints)
8. **Settings** — Organization settings (1 endpoint)

**Architecture:**

- `src/Livewire/` — Component classes (PHP)
- `src/Livewire/Concerns/` — Shared traits (token management, API calls, theming)
- `resources/views/livewire/widgets/` — Blade views (publishable)
- `resources/css/widgets.css` — Base stylesheet with `--woswidgets-*` defaults and `.woswidgets-*` styles (publishable)
- Service provider registration with `class_exists` guard
- `config/workos.php` `widgets` feature flag
- Token management via `WorkOS\Widgets::getToken()` with per-widget scopes

**Theming:**

- Full CSS parity with official `@workos-inc/widgets` package
- Same 4 `--woswidgets-*` CSS custom properties
- Same `.woswidgets-*` BEM-style class names on rendered HTML elements
- Base CSS file that matches official widget defaults
- Support `appearance` (light/dark) via `prefers-color-scheme` and explicit prop

**Component Design:**

- Each widget group has granular sub-components (e.g., `UserProfileInfo`, `SecuritySettings`)
- Each widget group has a pre-composed parent component (e.g., `UserProfile`)
- Components accept `wire:model`-compatible props where appropriate
- Components dispatch Livewire events on mutations (invite sent, role changed, etc.)

### Out of Scope

- React component wrappers — this is Livewire-only
- Admin Portal redirect flows — the SSO and Domain widgets link TO Admin Portal, they don't embed it
- Custom WorkOS API endpoints beyond the `/_widgets/*` namespace
- JavaScript-heavy interactions (passkey WebAuthn ceremony is browser-native, handled via Alpine.js)
- Real-time updates (webhooks could trigger Livewire polling, but that's a future enhancement)

### Future Considerations

- Livewire 4 compatibility (when released)
- Real-time widget updates via webhook-driven Livewire polling
- Headless mode (component logic without any views — for developers who want full UI control)
- Storybook-like preview page for all widgets in the workbench app

## Execution Plan

### Dependency Graph

```
Phase 1: Infrastructure (token management, base CSS, shared traits, service provider)
  ├── Phase 2: User Management widget (highest value, most complex table UI)
  ├── Phase 3: User Profile widget (most endpoints, elevated access)
  ├── Phase 4: Admin Portal widgets (SSO + Domain Verification — small, linked)
  └── Phase 5: Remaining widgets (API Keys, Data Integrations, Directory Sync, Settings)
```

### Execution Steps

**Strategy**: Sequential (Phase 1 blocks all, then Phases 2-5 could parallelize but share files)

1. **Phase 1** — Infrastructure _(blocking)_
   ```bash
   /execute-spec docs/ideation/livewire-workos-widgets/spec-phase-1.md
   ```

2. **Phase 2** — User Management widget
   ```bash
   /execute-spec docs/ideation/livewire-workos-widgets/spec-phase-2.md
   ```

3. **Phase 3** — User Profile widget
   ```bash
   /execute-spec docs/ideation/livewire-workos-widgets/spec-phase-3.md
   ```

4. **Phase 4** — Admin Portal widgets (SSO + Domain Verification)
   ```bash
   /execute-spec docs/ideation/livewire-workos-widgets/spec-phase-4.md
   ```

5. **Phase 5** — API Keys, Data Integrations, Directory Sync, Settings
   ```bash
   /execute-spec docs/ideation/livewire-workos-widgets/spec-phase-5.md
   ```

---

_This contract was generated from brain dump input. Review and approve before proceeding to specification._
