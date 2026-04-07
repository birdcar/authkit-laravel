---
phase: 04-workbench-example-app
reviewed: 2026-04-07T00:00:00Z
depth: standard
files_reviewed: 2
files_reviewed_list:
  - .gitignore
  - workbench/composer.json
findings:
  critical: 0
  warning: 2
  info: 2
  total: 4
status: issues_found
---

# Phase 4: Code Review Report

**Reviewed:** 2026-04-07
**Depth:** standard
**Files Reviewed:** 2
**Status:** issues_found

## Summary

Reviewed the root `.gitignore` and `workbench/composer.json` for the workbench example app phase. No critical security or correctness issues were found. Two warnings address an inconsistent package manager state (both `bun.lock` and `package-lock.json` are committed to the workbench) and a `setup` script that calls `npm install` when the project convention is `bun`. Two info items address a redundant `.gitignore` entry and a missing `--no-interaction` flag on an artisan migration call.

## Warnings

### WR-01: Conflicting lock files committed for workbench frontend dependencies

**File:** `workbench/composer.json:62`
**Issue:** The `setup` script calls `npm install`, but `workbench/bun.lock` is also committed to git alongside `workbench/package-lock.json`. This means two different package managers (`bun` and `npm`) have both been used in the workbench, producing two separate lock files with potentially divergent dependency trees. The project convention in `CLAUDE.md` states bun is the preferred package manager. A developer running `setup` with npm gets a different (potentially incompatible) install than a developer who ran `bun install` previously.
**Fix:** Pick one package manager and delete the other lock file. Given project conventions, replace `npm install` and `npm run build` in the setup script with `bun install` and `bun run build`, then delete `workbench/package-lock.json` from the repo:
```json
"setup": [
    "composer install",
    "@php -r \"file_exists('.env') || copy('.env.example', '.env');\"",
    "@php artisan key:generate",
    "@php artisan migrate --force",
    "bun install",
    "bun run build"
]
```

### WR-02: `setup` script runs `migrate --force` without interaction guard

**File:** `workbench/composer.json:60`
**Issue:** The `setup` script runs `@php artisan migrate --force`. The `--force` flag bypasses the production environment prompt, which is the intent for a scripted setup. However, the script does not pass `--no-interaction`, so if a migration triggers any interactive prompt (e.g., a confirmation on a destructive schema change added in the future), the script will hang in CI or non-TTY environments.
**Fix:** Add `--no-interaction` to both migration calls for consistent non-interactive behavior:
```json
"@php artisan migrate --force --no-interaction",
```
and in `post-create-project-cmd`:
```json
"@php artisan migrate --graceful --ansi --no-interaction"
```

## Info

### IN-01: Root `.gitignore` entry for `workbench/auth.json` is redundant

**File:** `.gitignore:22`
**Issue:** The root `.gitignore` contains `workbench/auth.json` to protect Flux Pro credentials. However, `workbench/.gitignore` already ignores `/auth.json` (line 14). The root entry is redundant and may give a false impression that only the root `.gitignore` provides protection.
**Fix:** The redundancy is harmless from a security standpoint — both rules protect the file. If the root entry is removed, the credential is still protected by `workbench/.gitignore`. This is purely cosmetic; keep the root entry for belt-and-suspenders security if preferred, but document the intentional overlap with a comment:
```gitignore
# Workbench credentials (also in workbench/.gitignore as defense-in-depth)
workbench/auth.json
```

### IN-02: `dev` script references `concurrently` via `npx` rather than a local binary

**File:** `workbench/composer.json:66`
**Issue:** The `dev` script calls `npx concurrently ...`. The workbench `package.json` lists `concurrently` as a dependency (visible from the installed `node_modules`), so calling it via `npx` adds network round-trip risk — `npx` will fall back to downloading the package from the npm registry if the local install is missing. Using the local binary path is more deterministic.
**Fix:** Reference the local binary instead:
```json
"./node_modules/.bin/concurrently -c ..."
```
Or if switching to bun (per WR-01), use `bunx concurrently` which resolves locally first.

---

_Reviewed: 2026-04-07_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
