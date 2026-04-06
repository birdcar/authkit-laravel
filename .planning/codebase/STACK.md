# Technology Stack

**Analysis Date:** 2026-04-06

## Languages

**Primary:**
- PHP 8.3 - Main implementation language with strict mode (`declare(strict_types=1)` required in all files)

**Secondary:**
- JavaScript/TypeScript - Build tooling and frontend assets (Vite, Tailwind)

## Runtime

**Environment:**
- PHP 8.3+ (8.4+ supported)
- Composer for dependency management
- Lockfile: `composer.lock` present

## Frameworks

**Core:**
- Laravel 11.x & 12.x - Web framework for the service provider pattern and routing
- Illuminate (Contracts, Support, Routing, Cookie, Event, Blade, Auth) - Laravel core components used throughout

**Frontend/Build:**
- Vite - Asset bundler and dev server
- Tailwind CSS 4.x - Utility-first CSS framework
- Laravel Vite Plugin - Integration between Laravel and Vite

**Testing:**
- PHPUnit (Pest) 3.x (4.x in workbench) - Test runner via `vendor/bin/pest`
- Mockery 1.6+ - Mocking library for tests

**Development:**
- Pint 1.x - PHP code formatter and style fixer (Laravel preset + strict types requirement)
- PHPStan 1.x - Static type analyzer at level 8

## Key Dependencies

**Critical:**
- `workos/workos-php` 4.29+ - Official WorkOS PHP SDK providing UserManagement, Organizations, SSO, DirectorySync, MFA, Portal, AuditLogs, and Webhook services
- `illuminate/contracts` 11.x|12.x - Interface contracts for dependency injection
- `illuminate/support` 11.x|12.x - Helper functions and utilities

**Infrastructure:**
- Laravel's built-in session management via cookies with Halite-based encryption
- Laravel's event system for webhook handling and user lifecycle events
- Laravel's auth guard pattern for custom WorkOS authentication

## Configuration

**Environment:**
Configured via `config/workos.php` with environment variables:
- `WORKOS_API_KEY` - WorkOS API key for backend operations
- `WORKOS_CLIENT_ID` - WorkOS OAuth client ID
- `WORKOS_REDIRECT_URI` - OAuth redirect URL (defaults to `{APP_URL}/auth/callback`)
- `WORKOS_WEBHOOK_SECRET` - Webhook signature verification secret
- `WORKOS_COOKIE_NAME` - Session cookie name (defaults to `wos-session`)
- `WORKOS_FEATURE_AUDIT_LOGS` - Enable audit log feature
- `WORKOS_FEATURE_ORGANIZATIONS` - Enable organization management
- `WORKOS_FEATURE_IMPERSONATION` - Enable user impersonation
- `WORKOS_FEATURE_WEBHOOKS` - Enable webhook handling
- `WORKOS_WEBHOOK_SYNC_ENABLED` - Enable automatic webhook event syncing

**Build:**
- `vite.config.js` in workbench for asset compilation
- `phpunit.xml` for test configuration
- `phpstan.neon` for static analysis configuration at level 8
- `pint.json` for code style with Laravel preset and strict types

## Platform Requirements

**Development:**
- PHP 8.3+ with extensions: mbstring, xml, ctype, json, curl
- Composer 2.x
- Node.js 18+ (for frontend build tools)
- SQLite or database of choice (migrations included)

**Production:**
- PHP 8.3+ application server (Laravel deployment)
- HTTP client capable of making requests to WorkOS API endpoints
- Session cookie support with HTTP-only and secure flags
- HTTPS for security (workos-session cookie)

---

*Stack analysis: 2026-04-06*
