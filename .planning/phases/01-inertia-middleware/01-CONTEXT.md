# Phase 1: Inertia Middleware - Context

**Gathered:** 2026-04-06 (assumptions mode)
**Status:** Ready for planning

<domain>
## Phase Boundary

Package consumers can share WorkOS auth state to Inertia frontends without any hard Inertia dependency. The `workos.inertia` middleware alias resolves to a working ShareWorkOSData class. Non-Inertia apps are unaffected.

</domain>

<decisions>
## Implementation Decisions

### Implementation Completeness
- **D-01:** ShareWorkOSData middleware is already fully implemented at `src/Http/Middleware/ShareWorkOSData.php` with 7 passing tests. Phase 1 is verification and polish, not greenfield development.
- **D-02:** The `workos.inertia` alias is registered in `WorkOSServiceProvider::configureMiddleware()` pointing to `ShareWorkOSData::class`.

### Soft Dependency Strategy
- **D-03:** `class_exists(Inertia::class)` runtime guard is sufficient — no `suggest` entry in `composer.json` or conditional service provider registration needed. This matches the project's existing duck-typing patterns.

### Shared Props Surface
- **D-04:** The `auth` prop shape is: `check`, `user` (id, workos_id, name, email), `roles`, `permissions`, `organization`, `impersonating`, `impersonator`. This is the complete surface for Phase 1.
- **D-05:** Sensitive fields (`accessToken`, `refreshToken`, `expiresAt`, `sessionId`) are intentionally excluded from shared props.

### Lazy Evaluation
- **D-06:** Inertia::share() closures are evaluated lazily — only when rendering Inertia responses, not on plain JSON/API responses. The current closure-based approach (`fn () => $this->getAuthData($request)`) is correct and performant.

### Claude's Discretion
- Whether to add `organizationName` to props (currently only `organizationId`) — defer to Phase 4 workbench needs
- PHPStan annotation style for dynamic Authenticatable property access

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Existing Implementation
- `src/Http/Middleware/ShareWorkOSData.php` — The middleware implementation (already exists)
- `tests/Feature/InertiaMiddlewareTest.php` — 7 tests covering auth/unauth states
- `src/WorkOSServiceProvider.php` — Middleware registration at `configureMiddleware()`

### Auth State Sources
- `src/Auth/WorkOSSession.php` — Value object with all session fields
- `src/Auth/SessionManager.php` — Session access and refresh logic

### Pattern References
- `src/Http/Middleware/SetCurrentOrganization.php` — Existing middleware pattern to follow
- `src/Http/Middleware/CheckRole.php` — Another middleware pattern reference

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `ShareWorkOSData` middleware: Already complete with `class_exists` guard, closure-based sharing, and full auth data extraction
- `WorkOSSession`: Provides all auth fields needed for shared props
- `SessionManager::getSession()`: Returns cached WorkOSSession or null

### Established Patterns
- Middleware registered as aliases in `WorkOSServiceProvider::configureMiddleware()`
- Runtime duck-typing with `class_exists`/`method_exists` rather than interface enforcement
- PHPStan level 8 with `@phpstan-ignore` for dynamic property access on Authenticatable

### Integration Points
- Middleware hooks into `SessionManager::getSession()` for auth state
- `Inertia::share()` receives closure that's lazily evaluated
- Tests use Mockery to simulate Inertia facade

</code_context>

<specifics>
## Specific Ideas

No specific requirements — the implementation already exists and tests pass. Phase 1 work is review, PHPStan verification, and sign-off.

</specifics>

<deferred>
## Deferred Ideas

- Adding `organizationName` to shared props — evaluate during Phase 4 (Workbench Example App)
- Session expiry info in props for client-side refresh — out of scope per D-05

</deferred>

---

*Phase: 01-inertia-middleware*
*Context gathered: 2026-04-06*
