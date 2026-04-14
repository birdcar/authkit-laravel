# Context Map: WorkOS SDK v5 Migration

**Phase**: 1
**Scout Confidence**: 82/100
**Verdict**: GO

## Dimensions

| Dimension | Score | Notes |
|---|---|---|
| Scope clarity | 19/20 | All 6 files identified with exact line references. Only gap is exact v5 class names (acknowledged in spec open items). |
| Pattern familiarity | 18/20 | All pattern files read. Current patterns well understood. |
| Dependency awareness | 18/20 | Direct consumers fully mapped. Indirect consumers (widgets, Livewire) PHPStan-excluded and low-risk for Phase 1. |
| Edge case coverage | 13/20 | v5 SDK surface unverified until installed. Constructor signature, getAuthorizationUrl return type unknown. |
| Test strategy | 14/20 | PHPStan as primary loop is clear. WorkOSServiceTest will break (expected). Test infrastructure understood. |

## Key Patterns

- `src/WorkOS.php` — SERVICE_MAP + __call() magic for v4 services; typed accessors for common services; feature-gated sub-services with RuntimeException guards; static testing helpers
- `src/WorkOSServiceProvider.php:197-214` — configureWorkOSSdk() uses static WorkOS::setApiKey()/setClientId(); lazy validation in singleton factory
- `src/Testing/WorkOSFake.php` — standalone fake (not extending WorkOS), swapped into container; mirrors a subset of WorkOS methods

## Dependencies

- `src/WorkOS.php` — consumed by → `src/Facades/WorkOS.php`, `src/Testing/WorkOSFake.php`, `tests/Unit/WorkOSServiceTest.php`, every controller/command via Facade
- `src/WorkOSServiceProvider.php` — consumed by → Laravel auto-discovery, `tests/Feature/ServiceProviderTest.php`
- `src/Facades/WorkOS.php` — consumed by → `src/Auth/SessionManager.php:159`, all controllers, commands, Blade directives, middleware
- `src/Audit/AuditLogger.php` — constructor type-hints `AuditLogs` (v4 class) — must update for Phase 1
- `src/FeatureFlags/FeatureFlagService.php` — constructor type-hints `Organizations` (v4 class) — must update for Phase 1

## Conventions

- **Naming**: PascalCase classes, camelCase methods, readonly properties with constructor promotion
- **Imports**: WorkOS SDK imports at top, grouped; illuminate imports before authkit internals
- **Error handling**: Silent catch returning null for non-critical failures; RuntimeException for disabled features
- **Types**: Strict types in every file; PHPStan level 8; typed properties
- **Testing**: Pest; WorkOSFake replaces the whole service; Mockery for SDK class mocks

## Risks

- v5 SDK constructor signature unverified — install first, check classes
- AuditLogger constructor type-hints v4 AuditLogs class — Phase 1 must update or PHPStan fails
- FeatureFlagService constructor type-hints v4 Organizations class — same issue
- WebhookController injects v4 Webhook class — PHPStan will flag but it's Phase 3 scope
- WorkOSServiceTest.php tests will fail (expected — deferred to later phases)
