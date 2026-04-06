# Technology Stack

**Analysis Date:** 2026-04-06

## Languages

**Primary:**
- PHP 8.3+ - Core language for all server-side logic in the Laravel package and workbench application

**Secondary:**
- JavaScript/TypeScript - Frontend tooling and build configuration (Vite-based)
- SQL - Database queries (via Laravel Eloquent ORM)

## Runtime

**Environment:**
- PHP 8.3 minimum (enforced via `composer.json` constraint `php: ^8.3`)
- Laravel 11+ and 12+ (via `illuminate/contracts` and `illuminate/support` packages)

**Package Manager:**
- Composer - PHP dependency management
- Lockfile: `composer.lock` present (both in root and workbench)
- npm - Node.js package management for frontend tooling
- Lockfile: `package-lock.json` (in workbench, not committed)

## Frameworks

**Core:**
- Laravel Framework 11+/12+ - Web application framework
  - Location: `illuminate/framework` dependency
  - Used for routing, middleware, service providers, database migrations, authentication
  
- Laravel Tinker ^2.10.1 - Interactive shell for workbench (dev only)

**UI/Frontend:**
- Livewire ^4.0 - Reactive Laravel components
- Flux/Flux Pro ^2.11 - Headless UI component system
  - Custom composer repository: https://composer.fluxui.dev
  
- Tailwind CSS ^4.1.18 - Utility-first CSS framework
- Vite ^7.0.7 - Frontend build tool and development server
- Laravel Vite Plugin ^2.0.0 - Vite integration for Laravel
- Tailwind CSS Vite Plugin ^4.1.18 - Tailwind integration with Vite

**Testing:**
- Pest PHP ^3.0 (main library) / ^4.0 (workbench) - Testing framework built on PHPUnit
- Pest Plugin Browser ^4.0 - Browser testing for Pest (workbench)
- Pest Plugin Laravel ^4.0 - Laravel testing utilities for Pest (workbench)
- PHPUnit ^12.0 - Test runner (workbench)
- Mockery ^1.6 - Mocking library for tests

**Build/Dev:**
- Laravel Pint ^1.0 - Code style fixer for Laravel
- PHPStan ^1.0 - Static analysis tool (level 8)
- Laravel Pail ^1.2.2 - Real-time log monitoring (dev only)
- Laravel Sail ^1.41 - Docker environment setup (dev only)
- Concurrently ^9.0.1 - Run multiple npm scripts concurrently
- Faker PHP ^1.23 - Generates fake data for testing

## Key Dependencies

**Critical:**
- workos/workos-php ^4.29 - Official WorkOS PHP SDK
  - Provides access to UserManagement, Organizations, DirectorySync, MFA, SSO, Webhooks, Audit Logs, Portal, and Passwordless services
  - File: `src/WorkOS.php` exposes service methods
  
- workos/authkit-laravel @dev - The package itself (symlinked in workbench via path repository)

**HTTP/Client:**
- guzzlehttp/guzzle - HTTP client (dependency of workos-php)
- guzzlehttp/promises - Promise handling (dependency of workos-php)
- guzzlehttp/psr7 - PSR-7 HTTP message implementation

**Infrastructure:**
- illuminate/contracts ^11.0|^12.0 - Interface contracts for Laravel
- illuminate/support ^11.0|^12.0 - Support utilities and helpers
- orchestra/testbench ^9.0|^10.0 - Testing infrastructure for Laravel packages

## Configuration

**Environment:**
Configuration is managed through:
- `config/workos.php` - Main configuration file for WorkOS integration
- `.env` file (not committed) - Environment variables for sensitive values
- Environment variables required:
  - `WORKOS_API_KEY` - WorkOS API key (format: `sk_test_*`)
  - `WORKOS_CLIENT_ID` - OAuth client ID
  - `WORKOS_REDIRECT_URI` - OAuth callback URL (defaults to `{APP_URL}/auth/callback`)
  - `WORKOS_WEBHOOK_SECRET` - Secret for webhook signature verification
  - `WORKOS_COOKIE_NAME` - Session cookie name (defaults to `wos-session`)

**Build:**
- `pint.json` - Code style configuration (preset: "laravel", strict type declarations)
- `phpstan.neon` - Static analysis configuration (level 8)
- `vite.config.js` - Frontend build configuration (workbench only)
  - Plugins: laravel-vite-plugin, @tailwindcss/vite
  - Watches resources/css and resources/js

**Database:**
- SQLite (default for development in workbench)
- Supports MySQL, MariaDB, PostgreSQL, SQL Server via Laravel configuration
- Migrations handled by Laravel migration system
- Location: `database/migrations/`

## Platform Requirements

**Development:**
- PHP 8.3 or higher
- Composer 2.x
- Node.js 16+ (for npm/frontend tooling)
- Git

**Production:**
- PHP 8.3 or higher
- Laravel 11 or 12
- Web server (Apache/Nginx with PHP-FPM)
- Database (MySQL/PostgreSQL/SQLite/MariaDB/SQL Server)
- Optional: Redis for caching (configured but not required)

**Workbench Application:**
- Deployed as standalone Laravel application
- Can use any Laravel-compatible hosting (Laravel Forge, Vercel, Heroku, etc.)
- SQLite database (file-based, development only)

---

*Stack analysis: 2026-04-06*
