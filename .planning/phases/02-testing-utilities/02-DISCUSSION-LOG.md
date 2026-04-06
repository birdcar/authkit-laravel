# Phase 2: Testing Utilities - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions captured in CONTEXT.md — this log preserves the discussion.

**Date:** 2026-04-06
**Phase:** 02-testing-utilities
**Mode:** discuss (interactive)
**Areas discussed:** Container Swap, Workbench Example Tests, TearDown Enforcement

## Gray Areas Identified

### 1. Container Swap Completeness
- **Finding:** `app()->instance('workos', $fake)` is correct because `alias('workos', WorkOS::class)` ensures DI-injected `WorkOS` resolves through the same binding
- **User decision:** Add a test that explicitly verifies DI-injected `WorkOS` resolves to the fake

### 2. Workbench Example Tests
- **Finding:** Workbench tests exist (AuthTest, TodoTest, OrganizationTest) but use `$this->actingAs($user, 'workos')` — none use `WorkOS::fake()` or `actingAs()`
- **User decision:** Both — create new dedicated example file AND convert one existing test as reference

### 3. TearDown Enforcement
- **Finding:** `tearDownWorkOS()` is not auto-called by PHPUnit/Pest because it doesn't follow the `tearDown{TraitName}` convention
- **User decision:** Rename to `tearDownInteractsWithWorkOS()` for auto-invocation, remove old name entirely
