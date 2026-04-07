# Phase 4: Workbench Example App - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-04-07
**Phase:** 04-workbench-example-app
**Areas discussed:** Admin Portal intents, Audit log visibility, Test suite scope, Todo app completeness

---

## Admin Portal Intents

| Option | Description | Selected |
|--------|-------------|----------|
| Keep all 6 | Certificate Renewal already implemented. More complete demo. | ✓ |
| Trim to roadmap 5 | Remove Certificate Renewal to match scope exactly | |

**User's choice:** Keep all 6 — no reason to remove working code.

| Option | Description | Selected |
|--------|-------------|----------|
| Organization settings | Admin Portal features are per-org. Natural home. | ✓ |
| Dedicated admin page | Separate /admin route with its own layout | |

**User's choice:** Organization settings is correct.

---

## Audit Log Visibility

| Option | Description | Selected |
|--------|-------------|----------|
| WorkOS Dashboard link | Add link to WorkOS Dashboard audit log page | |
| Local audit log view | Livewire component calling WorkOS Audit Logs API | |
| Console log + link | Log to Laravel log AND provide dashboard link | |

**User's choice:** (Other) WorkOS Admin Portal has an Audit Log intent that takes users to an Audit Log UI. That's enough. Optionally allow local Livewire audit log viewer.

| Option | Description | Selected |
|--------|-------------|----------|
| Todo CRUD + auth events | Covers realistic use case | |
| Everything | All route actions including org switch, settings, admin portal | ✓ |
| Auth events only | Minimal pattern demonstration | |

**User's choice:** Everything — all actions audited for comprehensive demo.

---

## Test Suite Scope

| Option | Description | Selected |
|--------|-------------|----------|
| Key flows with fake | Auth, todo CRUD, org switching, permissions with WorkOS::fake() | ✓ |
| Comprehensive coverage | Every route, Livewire component, admin portal, audit | |
| Minimal smoke tests | Boot, login redirect, one todo | |

**User's choice:** Key flows with WorkOS::fake()/actingAs().

| Option | Description | Selected |
|--------|-------------|----------|
| Keep standalone | Serves as reference for package consumers | ✓ |
| Merge into app tests | Consolidate, fewer files | |

**User's choice:** Keep WorkOSFakeExampleTest standalone as reference.

---

## Todo App Completeness

| Option | Description | Selected |
|--------|-------------|----------|
| Functional and clean | Working CRUD, org-scoped, consistent with Flux Pro | |
| Showcase quality | Loading states, transitions, empty states, responsive | ✓ |
| Bare minimum | Basic forms and lists | |

**User's choice:** Showcase quality — this is the primary evaluation tool.

| Option | Description | Selected |
|--------|-------------|----------|
| Yes, basic RBAC | Admins delete any, members delete own. Blade directives. | |
| No RBAC in demo | Same permissions for all | |
| RBAC with middleware | CheckRole/CheckPermission on routes. Most realistic. | ✓ |

**User's choice:** RBAC with middleware — demonstrates real-world authorization patterns.

---

## Claude's Discretion

- Flux Pro component choices and layout
- Loading state implementation approach
- Empty state messaging
- Route-level RBAC assignment specifics
- Audit event naming conventions

## Deferred Ideas

None
