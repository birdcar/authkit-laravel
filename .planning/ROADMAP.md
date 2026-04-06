# Roadmap: AuthKit Laravel

## Overview

This milestone takes a mature, fully-functional auth package and adds the developer experience layer that makes it best-in-class: a soft-dependency Inertia middleware, verified testing utilities that let consumers write real tests against WorkOS-authenticated routes, a complete workbench example app demonstrating every feature, a hardened Smart Install command with conflict detection and three install modes, and a tight CI/CD pipeline with a comprehensive README. Phases execute in dependency order — the unimplemented Inertia alias is fixed first, testing utilities are verified second (workbench tests depend on them), then the example app, then Smart Install (independently testable), then CI/CD and docs last when all features exist to document.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

- [ ] **Phase 1: Inertia Middleware** - Implement ShareWorkOSData and resolve the dangling workos.inertia alias
- [ ] **Phase 2: Testing Utilities** - Verify and harden WorkOS::fake(), actingAs(), and audit assertions
- [ ] **Phase 3: Smart Install** - Three-mode install command with conflict detection and post-write verification
- [ ] **Phase 4: Workbench Example App** - Complete Laravel 12 Todo app demonstrating all package features
- [ ] **Phase 5: CI/CD and Documentation** - Hardened pipelines, auto-release, and comprehensive README

## Phase Details

### Phase 1: Inertia Middleware
**Goal**: Package consumers can share WorkOS auth state to Inertia frontends without any hard Inertia dependency
**Depends on**: Nothing (first phase)
**Requirements**: INRT-01, INRT-02
**Success Criteria** (what must be TRUE):
  1. An Inertia app can read authenticated user, org, roles, permissions, and impersonation state from shared props without additional boilerplate
  2. Installing the package in a non-Inertia app does not trigger any Inertia-related errors or warnings
  3. The `workos.inertia` middleware alias resolves to a working class (no silent failure)
**Plans**: 1 plan
Plans:
- [x] 01-01-PLAN.md — Audit existing ShareWorkOSData implementation and run quality gates
**UI hint**: yes

### Phase 2: Testing Utilities
**Goal**: Package consumers can write isolated, repeatable tests for WorkOS-authenticated routes using familiar Laravel fake patterns
**Depends on**: Phase 1
**Requirements**: TEST-01, TEST-02, TEST-03, TEST-04, TEST-05
**Success Criteria** (what must be TRUE):
  1. Calling `WorkOS::fake()` in a test replaces both the facade and DI-resolved instances so no real API calls are made
  2. `WorkOS::actingAs($user)` sets up an authenticated user with roles, permissions, and org context usable in route tests
  3. `assertAudited()` and `assertNotAudited()` pass or fail based on what the code under test actually logged
  4. Fake state does not bleed between tests — each `WorkOS::fake()` call produces a fresh instance
  5. Workbench example tests demonstrate the fake and actingAs patterns in working, runnable form
**Plans**: 2 plans
Plans:
- [x] 02-01-PLAN.md — Fix InteractsWithWorkOS teardown method name and add DI injection test
- [x] 02-02-PLAN.md — Create WorkOSFakeExampleTest.php and convert one AuthTest to fake pattern

### Phase 3: Smart Install
**Goal**: Developers can run `workos:install` against any Laravel app — including those with Breeze, Jetstream, Fortify, or laravel/workos — and get a correct, non-destructive installation
**Depends on**: Phase 1
**Requirements**: INST-01, INST-02, INST-03, INST-04, INST-05, INST-06, INST-07, INST-08
**Success Criteria** (what must be TRUE):
  1. Running `workos:install` on an app with an existing auth system produces a migration guide instead of silently overwriting it
  2. Running with `--force` overwrites all existing auth configuration without any prompts
  3. Running with `--mini` publishes only the config file and prints setup instructions — no file manipulation
  4. Running `workos:install` twice produces identical output with no duplicate env vars or conflicting configs
  5. Every file modification step either succeeds and is verified, or falls through to printed manual instructions

### Phase 4: Workbench Example App
**Goal**: Developers evaluating the package can run a complete, working Laravel 12 app that demonstrates every package feature with a realistic test suite
**Depends on**: Phase 2, Phase 3
**Requirements**: WORK-01, WORK-02, WORK-03, WORK-04, WORK-05, WORK-06, WORK-07
**Success Criteria** (what must be TRUE):
  1. The workbench app boots and serves a Todo app where todos are scoped per org
  2. All Admin Portal intents (SSO, Directory Sync, Audit Logs, Log Streams, Domain Verification) are accessible from the UI
  3. User actions in the Todo app appear in the audit log view
  4. The Pest feature test suite runs successfully using `WorkOS::fake()` without real API credentials
  5. Running `git ls-files workbench/auth.json` returns no output (credentials are not tracked)
**UI hint**: yes

### Phase 5: CI/CD and Documentation
**Goal**: Every PR runs the full quality suite automatically, releases are label-driven, and developers can understand and adopt the package from the README alone
**Depends on**: Phase 4
**Requirements**: CICD-01, CICD-02, CICD-03, CICD-04, DOCS-01, DOCS-02, DOCS-03, DOCS-04
**Success Criteria** (what must be TRUE):
  1. Opening a PR triggers tests, PHPStan level 8, and Pint across all four matrix combinations (PHP 8.3/8.4 x Laravel 11/12)
  2. Applying a semver label to a merged PR triggers an automated release with CHANGELOG entry
  3. The README CI badge reflects the current build status
  4. A developer with no prior WorkOS knowledge can install and configure the package in under 5 minutes using only the README

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Inertia Middleware | 0/1 | Not started | - |
| 2. Testing Utilities | 0/2 | Not started | - |
| 3. Smart Install | 0/? | Not started | - |
| 4. Workbench Example App | 0/? | Not started | - |
| 5. CI/CD and Documentation | 0/? | Not started | - |
