# External Integrations

**Analysis Date:** 2026-04-06

## APIs & External Services

**WorkOS Authentication & User Management:**
- WorkOS AuthKit - Enterprise authentication platform
  - SDK/Client: `workos/workos-php` ^4.29
  - Auth: `WORKOS_API_KEY` (env var)
  - Client ID: `WORKOS_CLIENT_ID` (env var)
  - Implementation: `src/WorkOS.php` - Facade providing access to WorkOS services
  - Services exposed:
    - `userManagement()` - User authentication, OAuth2 flow
    - `organizations()` - Organization management
    - `directorySync()` - SCIM directory synchronization
    - `mfa()` - Multi-factor authentication
    - `sso()` - Single sign-on
    - `webhook()` - Webhook event handling
    - `auditLogs()` - Audit logging
    - `portal()` - Admin portal
    - `passwordless()` - Passwordless authentication

**OAuth2 / Authentication Flow:**
- Provider: WorkOS AuthKit
- Redirect URI: Configured via `WORKOS_REDIRECT_URI` env var
- Implementation: `src/Http/Controllers/AuthController.php`
  - Login endpoint: `/auth/login` (GET) - generates WorkOS login URL
  - Callback endpoint: `/auth/callback` (GET) - handles OAuth2 callback with authorization code
  - Logout endpoint: `/auth/logout` (GET/POST) - handles session cleanup
  - Code exchange: Uses `WorkOS::userManagement()->authenticateWithCode()`

## Data Storage

**Databases:**
- Default: SQLite (file-based, development)
- Supported:
  - MySQL/MariaDB (production)
  - PostgreSQL (production)
  - SQL Server (production)
  - SQLite (development)
- Connection: `DB_CONNECTION` env var
- Client: Laravel Eloquent ORM (via `illuminate/support`)
- Migrations: `database/migrations/`
  - `add_workos_id_to_users_table.php` - Adds `workos_id` column to users table
  - `create_organizations_table.php` - Organizations managed by current user
  - `create_organization_memberships_table.php` - User organization membership mappings

**File Storage:**
- Local filesystem only (via Laravel filesystem abstraction)
- No cloud storage integrations detected
- Configuration: `FILESYSTEM_DISK` env var (defaults to 'local')

**Caching:**
- File-based (default)
- Optional Redis support (configured but not required)
- Configuration: `CACHE_STORE` env var (defaults to 'file')
- Redis connection: Via `REDIS_*` env vars (host, port, username, password)

**Sessions:**
- Session Driver: `SESSION_DRIVER` env var (defaults to 'file')
- WorkOS Cookie-Based Sessions: Primary mechanism
  - Cookie name: `WORKOS_COOKIE_NAME` (defaults to `wos-session`)
  - Encryption: Already encrypted by WorkOS Halite-based encryption
  - Laravel configuration: `config/session.php`
  - Excluded from Laravel's EncryptCookies middleware to prevent double-encryption

## Authentication & Identity

**Auth Provider:**
- WorkOS AuthKit (enterprise authentication platform)
  - Implementation approach: OAuth2 authorization code flow
  - Custom guard: `workos` guard registered in `src/WorkOSServiceProvider.php`
  - Session-based authentication via sealed `wos-session` cookie
  - Source of truth: WorkOS-issued signed cookie (not database-backed)

**User Model Integration:**
- Configurable model: `WORKOS_USER_MODEL` env var (defaults to `App\Models\User`)
- Workbench model: `App\Models\User` (Eloquent)
- WorkOS ID mapping: `workos_id` column (unique, nullable)
- Auto-creation: Users are created/updated on first login via OAuth callback
  - Implementation: `src/Http/Controllers/AuthController.php` `findOrCreateUser()` method
  - Supports custom methods: `findOrCreateByWorkOS()` or `findOrCreateFromWorkOS()`
  - Default sync: email, name (first + last name)

**Organization Support:**
- Configurable model: `WORKOS_ORGANIZATION_MODEL` env var
- Workbench model: `App\Models\Organization` (Eloquent)
- Feature flag: `WORKOS_FEATURE_ORGANIZATIONS` (enabled by default)
- Membership table: `organization_memberships` tracks roles and permissions per user-org
- Routes: Prefixed at `/organizations` (configurable via `WORKOS_FEATURE_ORGANIZATIONS`)

**Authorization:**
- Role-based access control (RBAC):
  - Stored in WorkOS and synced via webhooks
  - Check method: `WorkOS::hasRole(string $role)` or user model `hasWorkOSRole()`
  - Middleware: `workos.role` - Checks if user has required role
  
- Permission-based access control (PBAC):
  - Stored in WorkOS and synced via webhooks
  - Check method: `WorkOS::hasPermission(string $permission)` or user model `hasWorkOSPermission()`
  - Middleware: `workos.permission` - Checks if user has required permission

**Impersonation Support:**
- Feature flag: `WORKOS_FEATURE_IMPERSONATION` (enabled by default)
- Detection: `WorkOS::isImpersonating()` method
- Middleware: `workos.impersonation` - Detects impersonation context
- Use case: Admin users can impersonate other users for support/debugging

## Monitoring & Observability

**Error Tracking:**
- Not detected - No Sentry, Rollbar, or similar integration
- Laravel native error reporting: `report()` helper used in `src/Http/Controllers/WebhookController.php`

**Logs:**
- Approach: Laravel logging framework
- Log channels: Configured via `LOG_CHANNEL` env var
- Stack configuration: `LOG_STACK` (defaults to 'single')
- Log level: `LOG_LEVEL` env var (defaults to 'debug')
- Real-time monitoring: Laravel Pail available in development (`laravel/pail` package)
- Audit logging: Optional WorkOS audit logs (feature flag: `WORKOS_FEATURE_AUDIT_LOGS`)
  - Implementation: `src/Audit/AuditLogger.php`
  - Logs actions to WorkOS audit log API
  - Middleware: `workos.audit` - Tracks user actions

## CI/CD & Deployment

**Hosting:**
- Not specified - This is a Laravel package/library, not a hosted service
- Workbench application can be deployed to:
  - Laravel Forge
  - Heroku
  - Vercel
  - AWS (Lambda, EC2, Lightsail)
  - Any server supporting PHP 8.3+

**CI Pipeline:**
- Not detected in current codebase
- Testing: Uses Pest PHP for automated testing
- Code quality: PHPStan for static analysis
- Code style: Laravel Pint for formatting
- Manual commands available:
  - `composer test` - Run Pest tests
  - `composer test:coverage` - Run tests with coverage (minimum 80%)
  - `composer analyse` - Run PHPStan level 8 analysis
  - `composer format` - Format code with Pint
  - `composer format:test` - Check formatting without changes

## Environment Configuration

**Required env vars:**
- `WORKOS_API_KEY` - WorkOS API key (sk_test_* or sk_live_*)
- `WORKOS_CLIENT_ID` - OAuth client ID
- `WORKOS_REDIRECT_URI` - OAuth callback URL (e.g., `http://localhost:8000/auth/callback`)
- `WORKOS_WEBHOOK_SECRET` - For webhook signature verification

**Optional env vars:**
- `WORKOS_COOKIE_NAME` - Session cookie name (defaults to 'wos-session')
- `WORKOS_FEATURE_AUDIT_LOGS` - Enable audit logging (defaults to false)
- `WORKOS_FEATURE_ORGANIZATIONS` - Enable organization support (defaults to true)
- `WORKOS_FEATURE_IMPERSONATION` - Enable impersonation (defaults to true)
- `WORKOS_FEATURE_WEBHOOKS` - Enable webhooks (defaults to true)
- `WORKOS_WEBHOOK_SYNC_ENABLED` - Auto-sync webhook data to database (defaults to true)
- `WORKOS_USER_MODEL` - Custom user model FQCN (defaults to App\Models\User)
- `WORKOS_ORGANIZATION_MODEL` - Custom organization model FQCN (defaults to App\Models\Organization)

**Secrets location:**
- `.env` file (local development) - Not committed to git
- `.env.example` file - Template with placeholder values
- Production: Environment variables injected at deployment time (Heroku, Vercel, etc.)

## Webhooks & Callbacks

**Incoming Webhooks:**
- Endpoint: `/webhooks/workos` (configurable prefix)
- Signature verification: `WorkOS-Signature` header validation (3-minute tolerance)
- Implementation: `src/Http/Controllers/WebhookController.php`
- Event types handled:
  - `user.created` → `WorkOSUserCreated` event
  - `user.updated` → `WorkOSUserUpdated` event
  - `user.deleted` → `WorkOSUserDeleted` event
  - `organization.created` → `WorkOSOrganizationCreated` event
  - `organization.updated` → `WorkOSOrganizationUpdated` event
  - `organization.deleted` → `WorkOSOrganizationDeleted` event
  - `organization_membership.created` → `WorkOSMembershipCreated` event
  - `organization_membership.updated` → `WorkOSMembershipUpdated` event
  - `organization_membership.deleted` → `WorkOSMembershipDeleted` event
  - `session.created` → `WorkOSSessionCreated` event
  - `user.session_revoked` → `WorkOSSessionRevoked` event
  - `authentication.*_succeeded` (email_verification, magic_auth, mfa, oauth, password, sso) → `WorkOSSessionCreated` event

**Webhook Event Processing:**
- Event framework: Laravel events
- Listeners: Auto-sync from webhooks to database
  - `SyncUserFromWebhook` - Syncs user data
  - `SyncOrganizationFromWebhook` - Syncs organization data
  - `SyncMembershipFromWebhook` - Syncs membership roles/permissions
- Events dispatched: `WebhookReceived` (generic) + specific event classes
- Configuration: Feature flags control webhook processing

**Outgoing Webhooks:**
- None detected - This is a library receiving webhooks, not sending them
- WorkOS events are consumed, not generated

---

*Integration audit: 2026-04-06*
