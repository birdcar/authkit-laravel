# PRD: AuthKit Laravel - Phase 1

**Contract**: ./contract.md
**Phase**: 1 of 6
**Focus**: Core package foundation - service provider, facade, auth guard, and session management

## Phase Overview

Phase 1 establishes the foundational architecture that all subsequent phases build upon. This includes the package structure, service provider registration, configuration system, and the core authentication primitives (guard and session manager).

This phase must come first because every other feature depends on having a working service provider, properly configured WorkOS SDK, and functional authentication guard. Without these, middleware can't check authentication, traits can't access session data, and the facade would have nothing to proxy.

After Phase 1 completes, developers can install the package, run the install command, and have a working AuthKit login flow. Users can authenticate via WorkOS and the Laravel app will recognize them as logged in. This provides immediate value even without advanced features.

## User Stories

1. As a Laravel developer, I want to install the package via Composer so that I can start integrating WorkOS AuthKit quickly
2. As a Laravel developer, I want to run an artisan command that sets up everything so that I don't have to manually configure multiple files
3. As a Laravel developer, I want to use a familiar facade pattern so that I can access WorkOS services without learning new patterns
4. As a Laravel developer, I want authentication to work through Laravel's native auth system so that existing `auth()` helpers and middleware work as expected
5. As a Laravel developer, I want sessions to auto-refresh so that users don't get unexpectedly logged out during active use

## Functional Requirements

### Package Structure

- **FR-1.1**: Package must be installable via `composer require workos/authkit-laravel`
- **FR-1.2**: Service provider must auto-register via Laravel's package discovery
- **FR-1.3**: Package must support Laravel 10, 11, and 12
- **FR-1.4**: Package must require PHP 8.1+ and workos/workos-php ^4.29

### Configuration

- **FR-1.5**: Config file must be publishable via `vendor:publish --tag=workos-config`
- **FR-1.6**: Config must support environment variables for all sensitive values (API key, client ID, webhook secret)
- **FR-1.7**: Config must include feature flags for optional functionality (audit_logs, organizations, impersonation, webhooks)
- **FR-1.8**: Config must allow customization of route prefixes and middleware
- **FR-1.9**: Config must NOT include session duration - this is controlled by WorkOS Dashboard only
- **FR-1.10**: Config may include `session.refresh_buffer_minutes` (how early to refresh before WorkOS expiry)

### Service Provider

- **FR-1.11**: Service provider must merge default config in `register()` method
- **FR-1.12**: Service provider must lazily configure WorkOS SDK (only when first accessed)
- **FR-1.13**: Service provider must register `workos` singleton binding
- **FR-1.14**: Service provider must alias `workos` to `WorkOS::class` for dependency injection
- **FR-1.15**: Service provider must register SessionManager as singleton

### Facade & Helper

- **FR-1.16**: WorkOS facade must proxy to `workos` service binding
- **FR-1.17**: Facade must include PHPDoc annotations for all proxied methods (IDE autocompletion)
- **FR-1.18**: `workos()` helper must return WorkOS service instance when called without arguments
- **FR-1.19**: `workos()` helper must support shortcuts: `workos('user')`, `workos('session')`, `workos('login')`, `workos('logout')`
- **FR-1.20**: Helper must be autoloaded via composer.json `files` array

### Auth Guard

- **FR-1.21**: Custom guard must be registered via `Auth::extend('workos', ...)`
- **FR-1.22**: Guard must implement `Illuminate\Contracts\Auth\Guard` interface
- **FR-1.23**: Guard must retrieve user from session manager and resolve via user provider
- **FR-1.24**: Guard must return null if no valid session exists
- **FR-1.25**: Guard must be configurable as default guard in `auth.php`

### Session Manager

- **FR-1.26**: Session manager must store WorkOS session data in Laravel's session store
- **FR-1.27**: Session manager must check session expiration using `expires_at` from WorkOS token (NOT local config)
- **FR-1.28**: Session manager must auto-refresh sessions when within configured buffer time of expiry (buffer is local config, expiry is from WorkOS)
- **FR-1.29**: Session manager must handle refresh failures gracefully (destroy session, return null)
- **FR-1.30**: Session manager must provide `isImpersonating()` method
- **FR-1.31**: Session lifetime must be controlled entirely by WorkOS Dashboard settings - no local session duration config

### Install Command

- **FR-1.32**: `workos:install` command must publish config file
- **FR-1.33**: `workos:install` command must publish migrations
- **FR-1.34**: `workos:install` command must update `config/auth.php` to add workos guard
- **FR-1.35**: `workos:install` command must display next steps (env vars, migrations, traits)
- **FR-1.36**: Command must be idempotent (safe to run multiple times)

### Migrations

- **FR-1.37**: Migrations must be publishable via `vendor:publish --tag=workos-migrations`
- **FR-1.38**: Migration must add `workos_id` column to users table
- **FR-1.39**: Migration must use `publishesMigrations()` for proper timestamping

## Non-Functional Requirements

- **NFR-1.1**: Service provider boot time must not exceed 5ms when features are disabled
- **NFR-1.2**: WorkOS SDK configuration must only happen once per request lifecycle
- **NFR-1.3**: Session refresh must complete within 500ms (network permitting)
- **NFR-1.4**: All secrets (API key, client ID) must never be logged or exposed in errors
- **NFR-1.5**: Package must pass Laravel Pint (code style) checks
- **NFR-1.6**: All classes must have strict types declared

## Dependencies

### Prerequisites

- None (this is the first phase)

### Outputs for Next Phase

- Working service provider with singleton bindings
- Configured WorkOS SDK
- Functional auth guard that can authenticate users
- Session manager that stores/retrieves/refreshes sessions
- WorkOS facade and helper available globally
- Published config file with feature flags
- Database migration for workos_id column

## Acceptance Criteria

- [ ] Package installs without errors via Composer
- [ ] Service provider auto-registers (verify in `php artisan about`)
- [ ] `workos:install` runs successfully and creates config file
- [ ] Config file contains all documented options
- [ ] `WorkOS::userManagement()` returns UserManagement instance
- [ ] `workos()->userManagement()` returns same instance
- [ ] `workos('user')` returns null when not authenticated
- [ ] Auth guard registers as 'workos' driver
- [ ] **`Auth::user()` returns authenticated User model when using workos guard**
- [ ] **`auth()->check()` returns true when user is authenticated**
- [ ] **`$request->user()` returns authenticated User model**
- [ ] **`@auth` and `@guest` Blade directives work correctly**
- [ ] Session data persists across requests
- [ ] Session auto-refreshes when near expiry
- [ ] All unit tests passing
- [ ] Code passes Laravel Pint checks

---

*Review this PRD and provide feedback before spec generation.*
