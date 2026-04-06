# Codebase Concerns

**Analysis Date:** 2026-04-06

## Tech Debt

**Broad Exception Handling:**
- Issue: Multiple catch blocks silently swallow generic `\Exception` without specific type handling
- Files: `src/Auth/SessionManager.php` (lines 46, 179), `src/Http/Controllers/AuthController.php` (line 104), `src/Http/Controllers/WebhookController.php` (line 75), `src/Http/Middleware/SetCurrentOrganization.php` (line 123)
- Impact: Makes debugging difficult, masks legitimate errors, prevents proper error recovery strategies
- Fix approach: Replace generic catches with specific exception types (e.g., `\WorkOS\Exceptions\*`, `\JsonException`, `\RuntimeException`). Add logging/reporting of different error types. Consider chaining specific exceptions with context.

**Dynamic Method Existence Checks:**
- Issue: Extensive use of `method_exists()` and `class_exists()` to determine capabilities rather than enforcing contracts
- Files: `src/Auth/WorkOSGuard.php` (line 49), `src/Http/Controllers/AuthController.php` (lines 128-131), `src/Http/Middleware/CheckOrganization.php` (line 32), `src/Models/Concerns/HasOrganization.php` (line 44), `src/Http/Middleware/SetCurrentOrganization.php` (line 45), `src/Listeners/SyncMembershipFromWebhook.php` (line 63)
- Impact: Makes code paths unclear, difficult to trace, creates fragile contracts. Behavior depends on runtime implementation details rather than explicit interfaces
- Fix approach: Create explicit interfaces (`UserHasWorkOS`, `ModelSyncable`) that enforce expected methods. Use interface checks instead of method_exists. Add PHPStan checks to enforce interface implementation at analysis time.

**Silent Fallback Behavior in User Creation:**
- Issue: `AuthController::findOrCreateUser()` tries multiple method names without clear priority, then falls back to `updateOrCreate()` 
- Files: `src/Http/Controllers/AuthController.php` (lines 128-139)
- Impact: Implicit behavior makes it unclear which method will execute. If an app implements wrong method name, will silently use fallback
- Fix approach: Document the priority order clearly. Consider throwing an exception if user model doesn't implement any expected method. Add logging when fallback is used.

**Hardcoded Session Expiry Defaults:**
- Issue: Multiple locations default session expiry to 1 hour without clear justification
- Files: `src/Auth/SessionManager.php` (line 192), `src/Auth/WorkOSSession.php` (line 46)
- Impact: If WorkOS API changes response format, sessions silently expire at wrong time. No way to override default
- Fix approach: Extract to config option `workos.session_default_expiry_hours`. Add explicit logging when fallback expiry is used. Sync with WorkOS Dashboard settings documentation.

**Missing Configuration Validation:**
- Issue: No validation of required config values at boot time
- Files: `src/WorkOSServiceProvider.php` — registers `WORKOS_API_KEY`, `WORKOS_CLIENT_ID` without checking they're set
- Impact: Cryptic errors occur only when using features, not at application boot
- Fix approach: Add `boot()` method to `WorkOSServiceProvider` that validates all required env vars are set. Throw clear exception with setup instructions if missing.

## Security Considerations

**Cookie Password Not Validated:**
- Risk: Session encryption depends on `APP_KEY`. If key is weak or compromised, all sessions are at risk
- Files: `src/Auth/SessionManager.php` (lines 80-84), `config/workos.php` (lines 40-45)
- Current mitigation: Relies on Laravel's APP_KEY strength validation (in framework)
- Recommendations: Document in config that session security depends entirely on APP_KEY strength. Consider adding HMAC verification in addition to encryption. Log when sessions cannot be decrypted (possible key rotation issues).

**Webhook Signature Tolerance:**
- Risk: 180-second tolerance for webhook signatures allows time-based replay attacks
- Files: `src/Http/Controllers/WebhookController.php` (line 66)
- Current mitigation: WorkOS SDK validates signature, tolerance is for clock skew
- Recommendations: Make tolerance configurable via env var. Document why 180s was chosen. Consider stricter tolerance (60s) for production. Add rate limiting on webhook endpoint.

**Impersonator Data Not Validated:**
- Risk: Impersonator info from WorkOS is stored without validation in sessions
- Files: `src/Auth/SessionManager.php` (line 197), `src/Models/Concerns/HasWorkOSPermissions.php` (line 55)
- Current mitigation: Data comes from WorkOS API, assumed trusted
- Recommendations: Add validation that impersonator contains expected fields (email, reason). Log all impersonations. Consider audit event for impersonation start/end.

**No Rate Limiting on Auth Endpoints:**
- Risk: Login/callback endpoints could be targeted for credential enumeration or DoS
- Files: `src/Http/Controllers/AuthController.php` — no explicit rate limiting middleware
- Current mitigation: None at package level; apps should add middleware
- Recommendations: Add rate limiting middleware to default routes. Document that auth routes must be rate-limited. Consider adding configurable threshold.

## Performance Bottlenecks

**Organization Sync on Every Request:**
- Problem: `SetCurrentOrganization` middleware calls WorkOS API to sync organization if not found in user's relationship
- Files: `src/Http/Middleware/SetCurrentOrganization.php` (lines 75-127)
- Cause: Fetches from API (line 78) and potentially creates/updates record every request if webhook sync is behind
- Improvement path: Add caching layer - cache organization data for 5-10 minutes. Only sync if cache miss AND org not in local relationship. Consider job queue for async sync rather than blocking request.

**Repeated Config Lookups:**
- Problem: `config('workos.user_model')`, `config('workos.organization_model')` called multiple times per request across different classes
- Files: `src/Listeners/SyncUserFromWebhook.php` (line 15), `src/Listeners/SyncMembershipFromWebhook.php` (lines 61, 92), `src/Http/Middleware/SetCurrentOrganization.php` (lines 67, 81)
- Cause: Config lookups are fast but unnecessary repetition. Each listener/middleware independently retrieves values
- Improvement path: Create service class that caches resolved model classes at container boot time. Inject `ModelResolver` instead of calling config() directly.

**Missing Query Optimization in Listeners:**
- Problem: Webhook listeners load full relationships without optimization
- Files: `src/Listeners/SyncMembershipFromWebhook.php` (line 49) calls `syncWithoutDetaching()` without specifying pivot columns
- Cause: Generic sync may load unnecessary data
- Improvement path: Explicitly select required columns. Consider batch processing multiple webhooks. Add N+1 query detection to tests.

## Fragile Areas

**Session Validation Logic:**
- Files: `src/Auth/SessionManager.php`
- Why fragile: Multiple conditional paths around cookie validation, decryption, refresh. If any step fails silently returns null. Cookie format from WorkOS SDK could change between versions
- Safe modification: Add integration tests for each failure path (missing cookie, invalid sealed format, expired). Mock `CookieSession` at all error points. Document expected sealed session structure
- Test coverage: Tests exist but should cover all exception paths explicitly rather than relying on catch-all

**User Creation Fallback Chain:**
- Files: `src/Http/Controllers/AuthController.php` (lines 112-142)
- Why fragile: Tries `findOrCreateByWorkOS` → `findOrCreateFromWorkOS` → fallback `updateOrCreate`. No clear contract. Easy to break if method names are inconsistent
- Safe modification: Add test for each possible path. Require explicit interface implementation. Document the precedence. Consider factory pattern instead of method chain
- Test coverage: Has tests but should enumerate all possible method combinations explicitly

**Webhook Event Mapping:**
- Files: `src/Http/Controllers/WebhookController.php` (lines 26-44)
- Why fragile: Hard-coded array maps event strings to classes. Multiple authentication events map to `WorkOSSessionCreated`. If WorkOS adds new event types, they're silently ignored
- Safe modification: Add logging for unknown event types. Consider creating listener registry rather than hard-coded map. Add test for unknown events
- Test coverage: Missing test for unmapped event types

**Organization Relationship Assumptions:**
- Files: `src/Http/Middleware/SetCurrentOrganization.php`, `src/Models/Concerns/HasOrganization.php`
- Why fragile: Code assumes `organizations()` returns `BelongsToMany` relationship. Uses dynamic relationship calls with `@phpstan-ignore`. If relationship structure changes, causes runtime errors
- Safe modification: Add explicit relationship interface/contract. Use type-safe relationship methods. Add relationship existence validation earlier in flow
- Test coverage: Test with missing/incorrect relationship definition

## Scaling Limits

**No Pagination in Sync Commands:**
- Current capacity: `workos:sync-users` fetches with default 100-user limit, loops through all pages
- Limit: Will block for large organizations (10k+ users). No progress feedback until completion
- Scaling path: Add `--chunk` option to process in batches with queue jobs. Add progress bar. Store sync state/cursor to allow resuming. Consider webhook-driven sync instead of bulk command

**Session Cache Unbounded:**
- Current capacity: `SessionManager::cachedSession` holds single session indefinitely
- Limit: Long-running processes (queue workers) may have stale session data
- Scaling path: Add TTL-based cache invalidation (5 minute max). Clear cache on organization switch. Consider redis-backed cache for multi-process apps

## Dependencies at Risk

**Hard Dependency on workos/workos-php 4.29+:**
- Risk: Sealed session format could change between major versions. Session encryption format might shift
- Impact: Upgrading could break sessions. No way to migrate sealed session data between versions
- Migration plan: Lock specific version range. Test sealed session compatibility when upgrading. Consider creating own session encryption wrapper to decouple

**Implicit Dependency on Eloquent for Webhook Sync:**
- Risk: All webhook listeners assume Eloquent models with specific structure
- Impact: Cannot use other ORMs or non-Eloquent data sources
- Migration plan: Consider introducing Repository pattern for data access. Create interface instead of assuming model methods exist

## Missing Critical Features

**No Webhook Replay/Retry Logic:**
- Problem: If webhook listener fails (listener throws exception), webhook is lost. No retry mechanism
- Blocks: Apps cannot reliably ensure user/org data stays in sync if webhooks fail
- Fix approach: Use Laravel's event queue instead of synchronous events. Add retry policy with exponential backoff. Store failed webhooks for manual replay

**No Session Revocation Notification:**
- Problem: `WorkOSSessionRevoked` event is fired but there's no mechanism to immediately invalidate affected user's local session
- Blocks: Logout could take several minutes (until cookie expires) if user clears WorkOS session
- Fix approach: Add cache invalidation when session revoked event received. Clear authenticated user immediately if they revoked their own session

**No Built-in Organization Onboarding Validation:**
- Problem: No check that user actually belongs to organization when selecting it during login
- Blocks: If WorkOS data is out of sync, user could login to wrong organization
- Fix approach: Add validation in `AuthController::callback()` that user belongs to selected organization before storing session

**Missing Configuration for Redirect Targets:**
- Problem: No way to customize redirect after login beyond `config('workos.routes.home')`
- Blocks: Cannot redirect to invitation acceptance, welcome flow, or other custom destinations
- Fix approach: Add `redirect_to` parameter support in login flow. Consider event hook for custom redirect logic

## Test Coverage Gaps

**Webhook Error Handling:**
- What's not tested: Invalid JSON payloads, malformed event data, missing required fields
- Files: `src/Http/Controllers/WebhookController.php`
- Risk: Silent failures when webhook data is corrupted. Events fire with incomplete data
- Priority: High - affects data consistency

**Session Refresh Path:**
- What's not tested: Token refresh failure scenarios, partial token updates, expired refresh token
- Files: `src/Auth/SessionManager.php::attemptRefresh()`
- Risk: Silent failures mask token expiration issues. Users left in authenticated state with invalid tokens
- Priority: High - affects security

**Organization Sync Conflicts:**
- What's not tested: When local org data conflicts with WorkOS data, name/slug changes, soft deletes
- Files: `src/Http/Middleware/SetCurrentOrganization.php`
- Risk: Data inconsistency, users accessing wrong organization data
- Priority: Medium - affects multi-tenant safety

**Impersonation Flow:**
- What's not tested: Impersonation start/end, impersonator info propagation, audit logging of impersonation
- Files: `src/Auth/SessionManager.php`, `src/Models/Concerns/HasWorkOSPermissions.php`
- Risk: No visibility into who impersonated whom or when
- Priority: Medium - affects compliance

**Permission/Role Permission Middleware:**
- What's not tested: Missing trait on user model (graceful vs hard error), null roles/permissions arrays
- Files: `src/Http/Middleware/CheckRole.php`, `src/Http/Middleware/CheckPermission.php`
- Risk: Exceptions thrown at request time rather than validation time
- Priority: Medium - affects application stability

---

*Concerns audit: 2026-04-06*
