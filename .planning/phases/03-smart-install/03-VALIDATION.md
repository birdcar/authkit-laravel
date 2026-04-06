---
phase: 3
slug: smart-install
status: draft
nyquist_compliant: true
wave_0_complete: true
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
| 03-01-01 | 01 | 1 | INST-01 | T-03-01 | array args prevent injection | unit | `composer test -- --filter=NodeToolingDetectorTest` | ❌ W0 | ⬜ pending |
| 03-01-02 | 01 | 1 | INST-02 | — | N/A | feature | `composer test -- --filter=InstallCommandTest` | ✅ | ⬜ pending |
| 03-02-01a | 02 | 2 | INST-02, INST-03, INST-04 | — | N/A | unit+feature | `composer test -- --filter=WizardFlowTest\|InstallCommandTest` | ✅ | ⬜ pending |
| 03-02-01b | 02 | 2 | INST-05 | — | N/A | unit | `composer test -- --filter=LaravelWorkosMigratorTest` | ❌ W0 | ⬜ pending |
| 03-02-02 | 02 | 2 | INST-06 | T-03-04 | empty placeholders only | feature | `composer test -- --filter=InstallCommandTest` | ✅ | ⬜ pending |
| 03-03-01 | 03 | 3 | INST-07 | T-03-05 | regex fallback to manual | unit | `composer test -- --filter=AuthSystemInstallerTest` | ❌ W0 | ⬜ pending |
| 03-03-02 | 03 | 3 | INST-08 | T-03-06 | per-key dedup | unit | `composer test -- --filter=EnvManagerTest` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Unit/NodeToolingDetectorTest.php` — created by Plan 03-01 Task 1
- [ ] `tests/Unit/LaravelWorkosMigratorTest.php` — created by Plan 03-02 Task 1
- [ ] `tests/Unit/AuthSystemInstallerTest.php` — created by Plan 03-03 Task 1
- [ ] `tests/Unit/EnvManagerTest.php` — created by Plan 03-03 Task 2

*Existing `tests/Feature/InstallCommandTest.php`, `tests/Unit/WizardFlowTest.php`, and `tests/Unit/EnvironmentDetectorTest.php` cover baseline.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| WorkOS CLI integration | INST-01 (D-01) | Requires Node runtime + network | Run `php artisan workos:install` on machine with npm/bun installed, verify CLI invocation |

*All other behaviors have automated verification.*

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 15s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
