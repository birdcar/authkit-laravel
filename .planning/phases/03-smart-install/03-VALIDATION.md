---
phase: 3
slug: smart-install
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-04-06
---

# Phase 3 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest PHP 3.x |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `composer test -- --filter=Install` |
| **Full suite command** | `composer test` |
| **Estimated runtime** | ~15 seconds |

---

## Sampling Rate

- **After every task commit:** Run `composer test -- --filter=Install`
- **After every plan wave:** Run `composer test`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 15 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 03-01-01 | 01 | 1 | INST-01 | — | N/A | unit | `composer test -- --filter=EnvironmentDetector` | ✅ | ⬜ pending |
| 03-01-02 | 01 | 1 | INST-07 | — | N/A | unit | `composer test -- --filter=Install` | ❌ W0 | ⬜ pending |
| 03-01-03 | 01 | 1 | INST-08 | — | N/A | unit | `composer test -- --filter=EnvManager` | ❌ W0 | ⬜ pending |
| 03-02-01 | 02 | 1 | INST-02 | — | N/A | feature | `composer test -- --filter=InstallCommand` | ✅ | ⬜ pending |
| 03-02-02 | 02 | 1 | INST-03 | — | N/A | feature | `composer test -- --filter=InstallCommand` | ✅ | ⬜ pending |
| 03-02-03 | 02 | 1 | INST-04 | — | N/A | feature | `composer test -- --filter=InstallCommand` | ✅ | ⬜ pending |
| 03-02-04 | 02 | 1 | INST-05 | — | N/A | unit | `composer test -- --filter=LaravelWorkosMigrator` | ❌ W0 | ⬜ pending |
| 03-02-05 | 02 | 1 | INST-06 | — | N/A | unit | `composer test -- --filter=MigrationPlan` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Unit/NodeToolingDetectorTest.php` — stubs for Node runtime detection
- [ ] `tests/Unit/LaravelWorkosMigratorTest.php` — stubs for config migration
- [ ] `tests/Unit/AuthSystemInstallerTest.php` — stubs for idempotent file edits
- [ ] `tests/Unit/EnvManagerTest.php` — stubs for duplicate key prevention

*Existing `tests/Feature/InstallCommandTest.php`, `tests/Unit/WizardFlowTest.php`, and `tests/Unit/EnvironmentDetectorTest.php` cover baseline.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| WorkOS CLI integration | INST-01 (D-01) | Requires Node runtime + network | Run `php artisan workos:install` on machine with npm/bun installed, verify CLI invocation |

*All other behaviors have automated verification.*

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 15s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
