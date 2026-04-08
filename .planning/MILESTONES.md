# Milestones

## v1.0 AuthKit Laravel v1.0 (Shipped: 2026-04-08)

**Phases completed:** 5 phases, 9 plans, 13 tasks

**Key accomplishments:**

- Fixed silent fake state bleed by renaming tearDownWorkOS to tearDownInteractsWithWorkOS so Laravel auto-invokes it, plus verified DI injection via app(WorkOS::class) resolves to the fake
- WorkOS fake patterns documented with runnable examples: direct fake lifecycle, InteractsWithWorkOS trait, and audit assertions; AuthTest dashboard test migrated from guard-based to WorkOS::actingAs().
- --force bypasses all install prompts and auto-selects replace strategy; --mini writes placeholder env vars and migration plan files via injected EnvManager and MigrationPlanGenerator
- AuthSystemInstaller hardening (`src/Install/AuthSystemInstaller.php`):
- Root .gitignore now excludes workbench/auth.json (Flux Pro credentials) and workbench/composer.json aligned to PHP ^8.3
- All workbench feature tests converted to WorkOS::fake() pattern; RBAC middleware demonstrated on todo routes with passing test coverage
- One-liner:

---
