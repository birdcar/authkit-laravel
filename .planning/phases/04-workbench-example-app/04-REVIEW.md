---
phase: 04-workbench-example-app
reviewed: 2026-04-07T00:00:00Z
depth: standard
files_reviewed: 6
files_reviewed_list:
  - .gitignore
  - workbench/composer.json
  - workbench/routes/web.php
  - workbench/tests/Feature/AuthTest.php
  - workbench/tests/Feature/OrganizationTest.php
  - workbench/tests/Feature/TodoTest.php
findings:
  critical: 0
  warning: 4
  info: 4
  total: 8
status: issues_found
---

# Phase 04: Code Review Report

**Reviewed:** 2026-04-07T00:00:00Z
**Depth:** standard
**Files Reviewed:** 6
**Status:** issues_found

## Summary

Reviewed the workbench example app's gitignore, composer configuration, route file, and full feature test suite. No critical security or correctness issues were found. All PHP files include `declare(strict_types=1)` and follow project naming conventions.

Four warnings address: a conflicting npm/bun lockfile situation in `composer.json`, a migration script missing `--no-interaction`, an audit log that fires before confirming successful deletion in the route closure, and an incomplete test assertion that claims to verify member visibility but only checks HTTP 200. Four info items cover the redundant `.gitignore` entry for workbench credentials, the `npx` vs local binary discrepancy, an unused return value in a test, and an inline FQCN in the route file that should be a `use` import.

## Warnings

### WR-01: Conflicting lock files committed for workbench frontend dependencies

**File:** `workbench/composer.json:62`
**Issue:** The `setup` script calls `npm install`, but `workbench/bun.lock` is also committed to git alongside `workbench/package-lock.json`. Two different package managers (`bun` and `npm`) have both been used in the workbench, producing two separate lock files with potentially divergent dependency trees. The project convention in `CLAUDE.md` states bun is the preferred package manager. A developer running `setup` with npm gets a different (potentially incompatible) install than a developer who ran `bun install` previously.
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

### WR-02: `setup` script runs `migrate --force` without `--no-interaction`

**File:** `workbench/composer.json:60`
**Issue:** The `setup` script runs `@php artisan migrate --force`. The `--force` flag bypasses the production environment prompt. However, the script does not pass `--no-interaction`, so if a migration triggers any interactive prompt (e.g., a confirmation on a destructive schema change added in the future), the script will hang in CI or non-TTY environments.
**Fix:** Add `--no-interaction` to both migration calls:
```json
"@php artisan migrate --force --no-interaction",
```
and in `post-create-project-cmd`:
```json
"@php artisan migrate --graceful --ansi --no-interaction"
```

### WR-03: Audit log fires before confirming successful deletion in route closure

**File:** `workbench/routes/web.php:27-29`
**Issue:** The `DELETE /todos/{todo}` route calls `$todo->delete()` then immediately calls `WorkOS::audit('todo.deleted', ...)` without checking the return value. If `delete()` returns false (e.g., a model `deleting` event listener cancels the operation by returning false) or throws an exception, the audit record is still emitted for a record that was never actually deleted. This creates a misleading audit trail.
**Fix:**
```php
Route::delete('/todos/{todo}', function (\App\Models\Todo $todo) {
    if (! $todo->delete()) {
        return response()->json(['message' => 'Could not delete todo'], 500);
    }

    \WorkOS\AuthKit\Facades\WorkOS::audit('todo.deleted', [
        ['type' => 'todo', 'id' => (string) $todo->id, 'name' => $todo->title],
    ]);

    return response()->json(['message' => 'Todo deleted']);
})->middleware('workos.role:admin')->name('todos.destroy');
```

### WR-04: `members list` test has no assertion on the tested behavior

**File:** `workbench/tests/Feature/OrganizationTest.php:51-63`
**Issue:** The test named `members list shows organization users` creates a named member (`Jane Doe`), attaches them to the organization, then requests `/organizations/settings` and asserts only `assertOk()`. The test never asserts that `Jane Doe` or any member data appears in the response. It passes as long as the page renders without a 500 — it does not detect a regression where members stop being displayed.
**Fix:**
```php
$this->withSession(['current_organization_id' => $org->id])
    ->get('/organizations/settings')
    ->assertOk()
    ->assertSee('Jane Doe');
```

## Info

### IN-01: Root `.gitignore` entry for `workbench/auth.json` duplicates inner `.gitignore`

**File:** `.gitignore:22`
**Issue:** The root `.gitignore` contains `workbench/auth.json` to protect Flux Pro credentials. `workbench/.gitignore` already ignores `/auth.json`. The root entry is redundant but harmless. The comment calling it out as "Workbench credentials" is good — consider noting the intentional overlap explicitly if the belt-and-suspenders pattern is intentional.
**Fix:** No change required for security. Optionally add a comment:
```gitignore
# Workbench credentials (also protected by workbench/.gitignore — defense-in-depth)
workbench/auth.json
```

### IN-02: `dev` script invokes `concurrently` via `npx` instead of local binary

**File:** `workbench/composer.json:66`
**Issue:** The `dev` script calls `npx concurrently ...`. If the local `node_modules` is absent, `npx` will attempt a network fetch from the npm registry. Using the local binary is more deterministic and works offline.
**Fix:** Reference the local binary or use the script alias:
```json
"./node_modules/.bin/concurrently -c ..."
```
Or if switching to bun (per WR-01): `bunx concurrently` resolves locally first.

### IN-03: Unused return value from `WorkOS::fake()` in `todos are scoped to organization`

**File:** `workbench/tests/Feature/TodoTest.php:95`
**Issue:** `WorkOS::fake()->actingAs($user, permissions: ['todos.read'])` returns a fake instance but the value is not assigned. This is inconsistent with other tests in the same file that capture `$fake` to call `$fake->assertAudited(...)`. While not a bug — no audit assertion is needed for this test — it creates a pattern inconsistency that could confuse readers.
**Fix:** The line is functionally correct as-is. For consistency with the rest of the file, either assign and leave unused (triggers a static analysis warning) or add a comment noting no audit assertion is needed:
```php
// No audit assertion needed — this test verifies scoping, not event firing
WorkOS::fake()->actingAs($user, permissions: ['todos.read']);
```

### IN-04: Route closure uses inline FQCN instead of `use` import

**File:** `workbench/routes/web.php:27`
**Issue:** `\WorkOS\AuthKit\Facades\WorkOS::audit(...)` uses a fully-qualified class name inline. The file already uses import-style references for `DashboardController`, `OrganizationController`, and `Route`. An inline FQCN is inconsistent with the project convention of using `use` statements.
**Fix:** Add to the imports at the top of the file:
```php
use WorkOS\AuthKit\Facades\WorkOS;
```
Then use `WorkOS::audit(...)` in the closure.

---

_Reviewed: 2026-04-07T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
