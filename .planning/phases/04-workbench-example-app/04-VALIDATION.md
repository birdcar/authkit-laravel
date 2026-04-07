---
phase: 4
slug: workbench-example-app
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-04-07
---

# Phase 4 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest PHP 4.x (workbench) |
| **Config file** | `workbench/phpunit.xml` |
| **Quick run command** | `composer test:example` |
| **Full suite command** | `composer test && composer test:example` |
| **Estimated runtime** | ~20 seconds |

---

## Sampling Rate

- **After every task commit:** Run `composer test:example`
- **After every plan wave:** Run `composer test && composer test:example`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 20 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 04-01-01 | 01 | 1 | WORK-06, WORK-07 | unit | `git ls-files workbench/auth.json` | ✅ | ⬜ pending |
| 04-01-02 | 01 | 1 | WORK-01, WORK-02 | feature | `composer test:example -- --filter=TodoTest` | ✅ | ⬜ pending |
| 04-02-01 | 02 | 2 | WORK-03, WORK-04 | feature | `composer test:example -- --filter=OrganizationTest` | ✅ | ⬜ pending |
| 04-02-02 | 02 | 2 | WORK-05 | feature | `composer test:example -- --filter=AuthTest\|TodoTest` | ✅ | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

*Existing test infrastructure covers all phase requirements. No new test files needed — existing AuthTest, TodoTest, OrganizationTest, WorkOSFakeExampleTest cover all flows.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Admin Portal links open WorkOS UI | WORK-03 | Requires real WorkOS credentials + browser | Click each Admin Portal link in org settings, verify WorkOS portal opens |
| Showcase UI quality | D-08 | Visual assessment | Review loading states, transitions, empty states, responsive layout in browser |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 20s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
