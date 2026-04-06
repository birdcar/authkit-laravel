---
phase: 02-testing-utilities
created: 2026-04-06
---

# Phase 02: Testing Utilities - Validation Strategy

## Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest PHP 3.8.6 (package), Pest 4.x (workbench) |
| Config file | `tests/Pest.php` (package), `workbench/tests/Pest.php` (workbench) |
| Quick run command | `composer test` |
| Full suite command | `composer test && composer analyse` |

## Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| TEST-01 | WorkOS::fake() and DI injection both resolve to fake | unit | `./vendor/bin/pest tests/Unit/WorkOSFakeTest.php` | Existing + new DI test |
| TEST-02 | actingAs() with roles/permissions/org context | unit | `./vendor/bin/pest tests/Unit/WorkOSFakeTest.php` | Already tested |
| TEST-03 | assertAudited/assertNotAudited assertions | unit | `./vendor/bin/pest tests/Feature/AuditIntegrationTest.php` | Already tested |
| TEST-04 | InteractsWithWorkOS auto-tears down fake | unit | `./vendor/bin/pest tests/Unit/WorkOSFakeTest.php` | New test needed |
| TEST-05 | Workbench example tests exist and run | feature | `cd workbench && ./vendor/bin/pest tests/Feature/WorkOSFakeExampleTest.php` | New file |

## Sampling Rate

- **Per task commit:** `./vendor/bin/pest tests/Unit/WorkOSFakeTest.php`
- **Per wave merge:** `composer test`
