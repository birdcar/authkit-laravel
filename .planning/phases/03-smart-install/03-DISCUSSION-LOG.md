# Phase 3: Smart Install - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-04-06
**Phase:** 03-smart-install
**Areas discussed:** --mini behavior, Conflict detection, Idempotency & verification, Migration guidance, WorkOS CLI integration

---

## --mini Behavior

| Option | Description | Selected |
|--------|-------------|----------|
| Current scope is right | Publish config + print instructions. Developers who pick --mini want minimal automation | |
| Even more minimal | Only print a link to docs — no config publish, no env detection | |
| Slightly more | Also write env vars to .env (but not touch auth.php, User model, or migrations) | ✓ |

**User's choice:** Slightly more — publish config AND write env placeholders for missing vars.

| Option | Description | Selected |
|--------|-------------|----------|
| Empty placeholders | Write WORKOS_API_KEY=, WORKOS_CLIENT_ID=, etc. | |
| Prompt for values | Ask for API key and client ID interactively | |
| Detect existing | If WORKOS_ vars exist, skip. Otherwise write placeholders. No prompting. | ✓ |

**User's choice:** Detect existing — skip present vars, write placeholders for missing ones, no prompts.

---

## Conflict Detection

| Option | Description | Selected |
|--------|-------------|----------|
| Composer + config only | Current depth is right. Route/middleware scanning is fragile. | ✓ |
| Add route scanning | Check if /auth/* routes already exist | |
| Add guard scanning | Check if 'workos' guard exists in auth.php | |

**User's choice:** Keep current detection depth — composer.json + config files only.

| Option | Description | Selected |
|--------|-------------|----------|
| Warn and continue | Show migration plan, warn, let wizard proceed | ✓ |
| Block without --force | Refuse to install unless --force passed | |
| Block only for Jetstream | Block for Jetstream (highest risk), warn for others | |

**User's choice:** Warn and continue — current behavior is correct.

---

## Idempotency & Verification

| Option | Description | Selected |
|--------|-------------|----------|
| Fallback to manual instructions | If edit fails, print what developer should add manually | ✓ |
| Fail the entire install | Abort and roll back on any failure | |
| Warn and continue | Print warning, continue with remaining components | |

**User's choice:** Fallback to manual instructions — never silently skip.

| Option | Description | Selected |
|--------|-------------|----------|
| Skip with confirmation | Detect existing entries, skip with info message | ✓ |
| Always overwrite | Re-run overwrites everything | |
| Diff and prompt | Show diff, ask to confirm each modification | |

**User's choice:** Skip existing entries with info message — no duplicates on re-run.

---

## Migration Guidance

| Option | Description | Selected |
|--------|-------------|----------|
| Inline console output | Print plan in terminal, no file | |
| Keep markdown file | Write to storage/ only | |
| Both | Console summary AND file for reference | ✓ |

**User's choice:** Both — print summary in console and write detailed plan to file.

| Option | Description | Selected |
|--------|-------------|----------|
| Actionable steps | Numbered list of specific files/changes | ✓ |
| High-level overview | General guidance, developer figures out specifics | |
| File-level diffs | Exact before/after for each file | |

**User's choice:** Actionable steps — concrete enough to follow without searching docs.

---

## WorkOS CLI Integration

**User-initiated area.** User referenced https://github.com/workos/cli and suggested integrating with `workos install` and `workos doctor`.

| Option | Description | Selected |
|--------|-------------|----------|
| Complement, don't compete | Detect Node, delegate env to CLI, handle Laravel config | |
| Fully independent | Ignore CLI, self-contained install | |
| Defer to CLI | Require CLI, error if not available | |

**User's choice:** (Other) Complement and actively delegate — use `npx/bunx workos@latest install` for initial setup when Node tooling available. Remove competing behavior. Use `workos doctor` for verification.

| Option | Description | Selected |
|--------|-------------|----------|
| Yes, detect Node → delegate → Laravel config | Full flow with fallback | ✓ |
| Always delegate, error if no Node | Require Node tooling | |

**User's choice:** Yes — detect Node, delegate to CLI if available, fallback to self-contained if not.

| Option | Description | Selected |
|--------|-------------|----------|
| Force only Laravel config | --force overwrites auth.php, traits, etc. CLI handles its own. | ✓ |
| Force everything | Pass through to CLI too | |
| Skip CLI on --force | Assume env already set up | |

**User's choice:** Force only Laravel config — CLI is independent.

---

## Claude's Discretion

- Node runtime detection implementation details
- Console output formatting for migration summaries
- Verification assertion placement (centralized vs per-installer)
- WorkOS CLI failure handling

## Deferred Ideas

- `workos:doctor` artisan command — v2 scope (DX-V2-01)
- `workos:upgrade` artisan command — v2 scope (DX-V2-02)
- Laravel Herd/Valet HTTPS guidance — v2 scope (DX-V2-03)
