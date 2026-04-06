# External Integrations

**Analysis Date:** 2026-04-06

## APIs & External Services

**WorkOS Platform:**
- **UserManagement** - User authentication and authorization
  - SDK/Client: `workos/workos-php` - `WorkOS\UserManagement`
  - Auth: `WORKOS_API_KEY`, `WORKOS_CLIENT_ID`
  - Methods: `authenticateWithCode()`, `getAuthorizationUrl()`
  - Used by: `src/Http/Controllers/AuthController.php`

- **Organizations** - Organization and membership management
  - SDK/Client: `workos/workos-php` - `WorkOS\Organizations`
  - Auth: `WORKOS_API_KEY`
  - Features gated by: `WORKOS_FEATURE_ORGANIZATIONS`
  - Used by: `src/Http/Controllers/OrganizationController.php`

- **SSO** - Single sign-on functionality
  - SDK/Client: `workos/workos-php` - `WorkOS\SSO`
  - Auth: `WORKOS_API_KEY`
  - Entry point: `src/WorkOS.php` service locator

- **DirectorySync** - User and organization directory synchronization
  - SDK/Client: `workos/workos-php` - `WorkOS\DirectorySync`
  - Auth: `WORKOS_API_KEY`
  - Entry point: `src/WorkOS.php` service locator

- **MFA** - Multi-factor authentication
  - SDK/Client: `workos/workos-php` - `WorkOS\MFA`
  - Auth: `WORKOS_API_KEY`
  - Entry point: `src/WorkOS.php` service locator

- **Portal** - Organization management portal
  - SDK/Client: `workos/workos-php` - `WorkOS\Portal`
  - Auth: `WORKOS_API_KEY`
  - Entry point: `src/WorkOS.php` service locator

- **AuditLogs** - Audit trail logging (optional feature)
  - SDK/Client: `workos/workos-php` - `WorkOS\AuditLogs`
  - Auth: `WORKOS_API_KEY`
  - Features gated by: `WORKOS_FEATURE_AUDIT_LOGS`
  - Implementation: `src/Audit/AuditLogger.php`

## Data Storage

**Databases:**
- Application responsibility - No hardcoded database integration
- Migrations provided in `database/migrations/`:
  - `add_workos_id_to_users_table.php` - Adds `workos_id` column to users
  - `create_organizations_table.php` - Organizations table
  - `create_organization_memberships_table.php` - Organization membership junction table
- Client: Configured by consuming Laravel application

**Session Storage:**
- Method: HTTP Cookie-based (WorkOS sealed session cookie)
- Cookie Name: `wos-session` (configurable via `WORKOS_COOKIE_NAME`)
- Encryption: Halite-based encryption via `WorkOS\Session\HaliteSessionEncryption`
- Duration: 30 days (configured in `src/Auth/SessionManager.php`)
- Excluded from Laravel's EncryptCookies middleware (double-encryption prevention)
- Implementation: `src/Auth/SessionManager.php`

**File Storage:**
- Not applicable - Package is authentication library only

**Caching:**
- Not used - Session data cached in-process via `WorkOSSession` object

## Authentication & Identity

**Auth Provider:**
- WorkOS AuthKit - OAuth 2.0 flow with WorkOS as identity provider
  - Implementation: `src/Auth/WorkOSGuard.php` - Custom Laravel guard
  - Session Manager: `src/Auth/SessionManager.php` - Cookie session management
  - User Provider: Configurable via `config/workos.user_model` (default: `App\Models\User`)
  - Login flow: `src/Http/Controllers/AuthController.php::login()` → OAuth redirect
  - Callback: `src/Http/Controllers/AuthController.php::callback()` → Token exchange
  - Session storage: WorkOS sealed cookie via `storeSession()`
  - Logout: `src/Http/Controllers/AuthController.php::logout()` → Destroys cookie

**Session Validation:**
- Cookie-based: `WorkOS\CookieSession` from SDK
- Token refresh: Automatic refresh on expiry via `src/Auth/SessionManager.php::attemptRefresh()`
- Token types: Access token + refresh token stored in sealed cookie

## Monitoring & Observability

**Error Tracking:**
- Not detected - Uses Laravel's default error reporting via `report()`
- Exception handling: Graceful error handling in webhook processing and authentication

**Logs:**
- Laravel application logging - No specific WorkOS logging configuration
- Audit logging: Optional feature via `WORKOS_FEATURE_AUDIT_LOGS` → `src/Audit/AuditLogger.php`

## CI/CD & Deployment

**Hosting:**
- Framework agnostic - Package for integration into Laravel applications
- Deployment: Standard Laravel deployment (`.planning/codebase/STRUCTURE.md` for consumer apps)

**CI Pipeline:**
- GitHub Actions (`.github/workflows/ci.yml`)
  - Test matrix: PHP 8.3 & 8.4 × Laravel 11.* & 12.*
  - Commands: `composer test`, `composer analyse`, `composer format:test`
  - Static analysis: PHPStan level 8 via `composer analyse`
  - Code style: Pint formatting check via `composer format:test`

## Environment Configuration

**Required env vars:**
- `WORKOS_API_KEY` - API authentication (from WorkOS dashboard)
- `WORKOS_CLIENT_ID` - OAuth client ID (from WorkOS dashboard)
- `WORKOS_REDIRECT_URI` - OAuth callback URL (defaults to `{APP_URL}/auth/callback`)
- `WORKOS_WEBHOOK_SECRET` - Webhook signature verification secret (from WorkOS dashboard)
- `APP_KEY` - Laravel app key (used for session cookie encryption derivation)
- `APP_URL` - Application URL (used for redirect URI default)

**Secrets location:**
- `.env` file (consumer application responsibility)
- Session encryption uses Laravel's `APP_KEY` via `SessionManager::__construct()`

## Webhooks & Callbacks

**Incoming:**
- Endpoint: `POST /webhooks/workos` (route prefix configurable)
- Handler: `src/Http/Controllers/WebhookController.php::handle()`
- Signature verification: `WorkOS\Webhook::constructEvent()` with 3-minute tolerance
- Events mapped in `WebhookController::EVENT_MAP`:
  - User events: `user.created`, `user.updated`, `user.deleted`
  - Organization events: `organization.created`, `organization.updated`, `organization.deleted`
  - Membership events: `organization_membership.created`, `organization_membership.updated`, `organization_membership.deleted`
  - Session events: `session.created`, `user.session_revoked`
  - Authentication events: `authentication.{method}_succeeded` (email_verification, magic_auth, mfa, oauth, password, sso)
- Event dispatching: Uses Laravel events system for async handling
- Auto-sync: Optional via `WORKOS_WEBHOOK_SYNC_ENABLED` → `src/Listeners/` listeners

**Outgoing:**
- Not applicable - Webhook receiver only

---

*Integration audit: 2026-04-06*
