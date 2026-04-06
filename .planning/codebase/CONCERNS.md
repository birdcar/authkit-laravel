# Codebase Concerns

**Analysis Date:** 2026-04-06

## Tech Debt

**Session Expiry Fallback Inconsistency:**
- Issue: `SessionManager::buildWorkOSSession()` hardcodes expiry to 1 hour when no expiry data is available, but `WorkOSSession::fromAuthResponse()` has more sophisticated fallback logic (checking both `expires_at` and `expires_in`)
- Files: `src/Auth/SessionManager.php:192`, `src/Auth/WorkOSSession.php:40-46`
- Impact: Session may expire before actual token expiry, causing unexpected logouts
- Fix approach: Align `buildWorkOSSession()` to use the same fallback logic as `fromAuthResponse()`, or ensure WorkOS cookie responses always include expiry data

**Broad Exception Catching:**
- Issue: Multiple places catch generic `\Exception` without specificity, swallowing errors and reducing debuggability
- Files: `src/Auth/SessionManager.php:46, 138, 179`, `src/Http/Controllers/AuthController.php:104`, `src/Http/Controllers/WebhookController.php:75`, `src/Http/Middleware/SetCurrentOrganization.php:123`, `src/Audit/AuditLogger.php:65`
- Impact: Silent failures make it hard to diagnose authentication, webhook, or audit logging issues. Errors are reported but context is lost
- Fix approach: Catch specific exception types (e.g., `WorkOSException`, `InvalidSignatureException`). Log error details with context before reporting

**Configuration File Manipulation via Regex:**
- Issue: `AuthSystemInstaller::updateAuthConfig()` and `updateWorkosConfigOrganizationModel()` use regex patterns to insert configuration into `config/auth.php` and `config/workos.php`
- Files: `src/Install/AuthSystemInstaller.php:159-161, 205-208, 216-219`
- Impact: Regex patterns are fragile and may fail if config file structure is slightly different (whitespace, comments, nested arrays). No rollback if update fails
- Fix approach: Parse config files into AST or use a config builder library instead of regex substitution. Validate config syntax after modification

**Magic Property Access in OrganizationController:**
- Issue: `OrganizationController::revokeInvitation()` accesses `$invitation->organizationId` which is a magic property accessed via WorkOS SDK's `__get` method
- Files: `src/Http/Controllers/OrganizationController.php:71-72`
- Impact: Type hints don't reflect actual property availability; IDE autocomplete won't work; code is fragile to SDK changes
- Fix approach: Extract property early with proper type assertion: `$orgId = $invitation->raw['organization_id'] ?? null`

## Known Issues

**Organization Switch Redirect Flow:**
- Issue: Organization switching redirects through WorkOS login to obtain new access token scoped to target org. This is a workaround for the session architecture
- Files: `src/Http/Controllers/OrganizationController.php:34`, recent commit `d4884dc`
- Trigger: User submits organization switch form
- Symptoms: Full redirect loop through WorkOS instead of in-app state change
- Workaround: Expected behavior after recent refactor; no workaround needed, but adds redirect latency
- Note: This is by design (BREAKING CHANGE in recent refactor) and correctly implemented, just worth noting for UX impact

**Null Safety with Method Existence Checks:**
- Issue: Code relies heavily on runtime `method_exists()` checks for optional trait/concern usage rather than interfaces
- Files: `src/Auth/SessionManager.php`, `src/Auth/WorkOSGuard.php:49`, `src/Http/Controllers/AuthController.php:128-131`, `src/Http/Middleware/SetCurrentOrganization.php:45, 105`, `src/Listeners/SyncMembershipFromWebhook.php:63, 78, 94`
- Impact: No compile-time guarantee that expected methods exist; caller must know implementation details
- Fix approach: Define interfaces for optional behaviors (e.g., `HasWorkOSSession`, `CanFindByWorkOS`) that implementers must explicitly extend

## Security Considerations

**Cookie Password Derivation:**
- Risk: `SessionManager` uses Laravel `app.key` as the encryption password for WorkOS session cookies. This couples session security to app.key rotation
- Files: `src/WorkOSServiceProvider.php:110-114`
- Current mitigation: Uses standard Laravel encryption; if app.key is compromised, all sessions are compromised
- Recommendations: 
  - Consider using a dedicated `WORKOS_SESSION_KEY` env var separate from `APP_KEY` for session encryption
  - Document that rotating `APP_KEY` requires invalidating all active sessions

**Webhook Signature Tolerance:**
- Risk: Webhook handler accepts signatures with 180-second (3-minute) tolerance for timestamp validation
- Files: `src/Http/Controllers/WebhookController.php:66`
- Current mitigation: Tolerance is reasonable for clock skew, but hardcoded. No configurable limit
- Recommendations:
  - Make tolerance configurable via `config/workos.php`
  - Document the 3-minute window to security-conscious implementers
  - Consider reducing to 60 seconds in production-like environments

**Organization Access Validation:**
- Risk: `OrganizationController::switch()` validates user belongs to org, but `invite()` and `revokeInvitation()` do not validate the user's organization permissions
- Files: `src/Http/Controllers/OrganizationController.php:37-61, 63-81`
- Current mitigation: Invitation endpoints may rely on route authorization, but it's not explicit in the controller
- Recommendations:
  - Add explicit authorization check: `$this->authorize('manage-organization', $organizationId)`
  - Document what permissions are required for each endpoint

## Performance Bottlenecks

**Organization Sync on Every Request:**
- Problem: `SetCurrentOrganization` middleware can trigger a WorkOS API call (`WorkOS::organizations()->getOrganization()`) if organization not found in local cache
- Files: `src/Http/Middleware/SetCurrentOrganization.php:75-128`
- Cause: Full eager-loading of organizations on user load (line 51) may not include the current session's org; fallback queries API
- Improvement path:
  - Cache organization lookups in request lifecycle (use `request()->cache()` or similar)
  - Pre-load current organization from session context
  - Add database indexes on `workos_id` for faster lookups

**Repeated Method Existence Checks:**
- Problem: `method_exists()` called repeatedly for the same methods in request lifecycle
- Files: Multiple middleware and listeners
- Cause: No caching of method resolution
- Improvement path:
  - Define static method registry in service provider boot
  - Use configuration flags instead of runtime checks
  - Cache reflection results in container

**Webhook Event Mapping:**
- Problem: Large EVENT_MAP array (16+ entries) in `WebhookController` checked for every webhook
- Files: `src/Http/Controllers/WebhookController.php:25-44`
- Current: Linear lookup is fine for small maps, but fragile as new events are added
- Improvement path:
  - Consider using a factory pattern or registry for event mapping
  - Add integration tests to ensure all WorkOS event types are mapped

## Fragile Areas

**File-Based Config Updates:**
- Files: `src/Install/AuthSystemInstaller.php`
- Why fragile: Regex-based config updates can break if:
  - User has commented-out config values
  - File has different whitespace or formatting
  - User has custom code interleaved in config arrays
  - File encoding differs (UTF-8 with BOM, etc.)
- Safe modification: 
  - Test against multiple Laravel config templates
  - Validate syntax with `php -l` after modification
  - Provide rollback instructions if update fails
- Test coverage: No specific tests for config file mutation failures

**Migration Plan Detection:**
- Files: `src/Install/EnvironmentDetector.php`, `src/Support/DetectionResult.php`
- Why fragile: Detects presence of Laravel auth scaffolding by file/class existence. If user has partial installs or non-standard structures, detection fails
- Safe modification:
  - Add logging to detection results
  - Provide manual override flags in install command
- Test coverage: `tests/Unit/EnvironmentDetectorTest.php` exists but limited to happy path

**User Model Method Dispatch:**
- Files: `src/Http/Controllers/AuthController.php:128-131`, `src/Listeners/SyncMembershipFromWebhook.php:58-68`
- Why fragile: Tries multiple method names (`findOrCreateByWorkOS`, `findOrCreateFromWorkOS`) in fallback chain. If user model implements one method with different signature, will silently fail
- Safe modification:
  - Document exact method signature requirements
  - Validate method signature with Reflection
  - Throw explicit error if method exists but has wrong signature
- Test coverage: Tests cover `findOrCreateByWorkOS()` but not fallback chain

**Organization Relationship Assumptions:**
- Files: `src/Http/Middleware/SetCurrentOrganization.php:44-62`, `src/Models/Concerns/HasOrganization.php`
- Why fragile: Assumes user model has `organizations()` relationship and uses specific table/pivot names
- Safe modification:
  - Validate relationship exists at boot time
  - Allow configuration of relationship name
  - Provide clear error messages if relationship is misconfigured
- Test coverage: Integration tests verify behavior, but no explicit validation tests

## Test Coverage Gaps

**Webhook Event Type Mapping:**
- What's not tested: Not all WorkOS event types in EVENT_MAP are validated to actually dispatch correct events
- Files: `src/Http/Controllers/WebhookController.php:26-44`, `tests/Feature/WebhookTest.php` missing comprehensive cases
- Risk: New event types added but never tested; silent event dispatch failures
- Priority: High - webhooks are critical to user/org sync

**Error Recovery in Session Manager:**
- What's not tested: Behavior when token refresh fails, when cookie encryption fails, when WorkOS SDK throws specific exceptions
- Files: `src/Auth/SessionManager.php`
- Risk: Silent nulls returned instead of proper error handling; hard to debug in production
- Priority: High - session management is core auth flow

**Config File Edge Cases:**
- What's not tested: Installing over existing partial configs, different Laravel versions, Windows file paths, non-ASCII characters in config
- Files: `src/Install/AuthSystemInstaller.php`
- Risk: Install command can leave app in broken state if config update fails
- Priority: Medium - affects first-time setup UX

**Organization Authorization:**
- What's not tested: Permission checks for organization invite/revoke endpoints; whether users can invite to orgs they don't belong to
- Files: `src/Http/Controllers/OrganizationController.php:37-81`
- Risk: Authorization bypass if route guards aren't properly configured
- Priority: High - security relevant

**Concurrent Request Handling:**
- What's not tested: Session caching behavior under concurrent requests; whether `$cachedSession` in SessionManager can cause race conditions
- Files: `src/Auth/SessionManager.php:15-18`
- Risk: Potential state leakage between requests in high-concurrency environments
- Priority: Medium - Laravel uses shared memory for request handling

## Missing Critical Features

**Session Invalidation on Role/Permission Changes:**
- Problem: When user's roles or permissions change in WorkOS, cached session isn't invalidated. User continues with stale permissions until session refreshes or expires
- Blocks: Real-time permission enforcement for high-security operations
- Workaround: Call `WorkOS::destroySession()` on permission update, force re-login
- Impact: Security risk for permission-dependent operations; may need rate-limiting or critical action verification

**Audit Log Failure Handling:**
- Problem: Audit logging failures are caught and reported but don't affect request flow. Failed audits are silently lost
- Blocks: Compliance-critical applications cannot guarantee audit trail completeness
- Workaround: Monitor error reports for audit failures; implement separate audit verification
- Impact: No way to detect audit log gaps; failed operations may go unrecorded

**Organization Role Hierarchy:**
- Problem: Org roles are simple strings with no hierarchy or inheritance. Cannot express "admin includes all permissions"
- Blocks: Fine-grained role-based access control beyond flat role list
- Workaround: Encode role hierarchy in application code; maintain separate permission matrix
- Impact: Limits multi-tenant authorization flexibility

## Dependencies at Risk

**WorkOS PHP SDK Breaking Changes:**
- Risk: Package depends on `workos-inc/sdk-php` but doesn't lock to specific version in docs
- Impact: Breaking SDK changes (e.g., response object structure, method signatures) could break session handling and webhooks
- Migration plan:
  - Pin SDK to known-working version in `composer.lock`
  - Add integration tests against SDK (not just mocks)
  - Monitor SDK changelog before upgrading

**Laravel Version Compatibility:**
- Risk: Dropped Laravel 10 and PHP 8.2 support in recent refactor. Apps on older versions cannot use new code
- Impact: Users on Laravel 10 must stay on older package version; no upgrade path without environment upgrade
- Migration plan:
  - Document version requirements clearly
  - Maintain separate branch for Laravel 10 backports if needed
  - Use semantic versioning to signal breaking changes

## Scaling Limits

**Session Storage via Cookies:**
- Current capacity: Cookie size limit ~4KB (after base64 encoding)
- Limit: If user has many roles/permissions, serialized session could exceed cookie limits
- Scaling path:
  - Use Redis/cache storage instead of cookies for session data
  - Store only token reference in cookie; fetch full session from server cache
  - Implement session pagination if role/permission list grows

**Organization Query N+1:**
- Current capacity: Middleware loads organizations for every request; if user in 100+ orgs, significant overhead
- Limit: More than ~50-100 organizations per user causes noticeable load
- Scaling path:
  - Add pagination/lazy-loading of user's organization list
  - Cache organization list separately from session
  - Add database query eager-loading

**Webhook Queue Backlog:**
- Current capacity: Webhooks are processed synchronously
- Limit: High-frequency WorkOS events can back up request handling
- Scaling path:
  - Queue webhook events to job queue (Laravel queue)
  - Add idempotency tokens to prevent duplicate processing
  - Implement exponential backoff for failed webhook processing

---

*Concerns audit: 2026-04-06*
