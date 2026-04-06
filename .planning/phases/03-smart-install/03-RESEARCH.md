# Phase 3: Smart Install - Research

**Researched:** 2026-04-06
**Domain:** Laravel artisan install command, WorkOS CLI integration, PHP Process facade, install idempotency
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**WorkOS CLI Integration**
- D-01: Detect Node tooling (npm/bun/pnpm) at install start. If available, delegate env/credential setup to `npx|bunx workos@latest install` before handling Laravel-specific config.
- D-02: If no Node tooling detected, fall back to self-contained env setup (current EnvManager behavior).
- D-03: For verification/diagnostics, use `npx|bunx workos@latest doctor` when Node tooling is available instead of building our own doctor command.
- D-04: `--force` only force-overwrites Laravel config files (auth.php, User model, etc.). WorkOS CLI handles its own env setup regardless of `--force`.

**--mini Behavior**
- D-05: `--mini` publishes `config/workos.php`, detects existing WORKOS_ env vars, writes placeholders for missing ones (no prompting), and prints remaining manual steps.
- D-06: `--mini` does NOT prompt for API key/client ID values -- just writes empty placeholders for missing vars.

**Conflict Detection**
- D-07: Detection stays at composer.json + config file level (current EnvironmentDetector scope). No route/middleware/model scanning -- too fragile for an install command.
- D-08: When existing auth detected (Breeze/Jetstream/Fortify), warn and continue. No blocking without `--force`. Show migration plan and let wizard proceed.

**Idempotency and Verification**
- D-09: Post-write verification (INST-07): if an automated file edit fails (regex did not match), fall back to printing exact manual instructions. Never silently skip.
- D-10: Re-run safety (INST-08): detect existing entries (guard in auth.php, traits in User model, env vars) and skip them with an info message. No duplicates.

**Migration Guidance**
- D-11: When existing auth detected, print migration summary in console AND write detailed plan to storage/ for reference.
- D-12: Migration plans are actionable numbered steps: specific files to change, what to remove, what to keep. Concrete enough to follow without external docs.

### Claude's Discretion
- Node runtime detection implementation (which command to check: `which node`, `which npx`, etc.)
- Exact console output formatting for migration summaries
- Whether to add verification assertions to component installers or centralize in WizardFlow
- How to handle WorkOS CLI failures gracefully (e.g., user cancels, network error)

### Deferred Ideas (OUT OF SCOPE)
- `workos:doctor` artisan command (DX-V2-01) -- v2 scope, reference WorkOS CLI doctor for now
- `workos:upgrade` artisan command (DX-V2-02) -- v2 scope
- Laravel Herd/Valet HTTPS setup guidance (DX-V2-03) -- v2 scope
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| INST-01 | Detect existing auth setups (laravel/workos, Breeze, Jetstream, Fortify) via Composer | EnvironmentDetector already implements composer.json detection. INST-01 is complete except for routing detection results to new Node-delegation flow. |
| INST-02 | Wizard mode (default) interactively asks which components to install | WizardFlow already implements 6-step wizard. New work: insert Node detection + WorkOS CLI delegation step before wizard, and wire `--force` to bypass all wizard prompts. |
| INST-03 | --force flag overwrites all existing auth configuration without prompting | Currently `--force` only passes to `vendor:publish`. WizardFlow must check command option and skip all confirms when `--force` is set. |
| INST-04 | --mini flag publishes only config and displays setup instructions | `handleMiniInstall` exists but does NOT write placeholder env vars -- D-05 requires writing placeholders to `.env`. Needs new `EnvManager::writePlaceholders()` or calling `applyChanges()` from mini path. |
| INST-05 | Config migration from services.php to workos.php when laravel/workos detected | `LaravelWorkosMigrator::handleServicesConfigCleanup()` exists. The existing regex removal pattern covers single-level array extraction. |
| INST-06 | Migration assistant generates actionable guidance for existing auth systems | `MigrationPlanGenerator` + per-package Plans already exist with numbered steps. D-11 requires console summary be printed for `--mini` path too (currently skipped). |
| INST-07 | Post-write verification for all file modifications (no silent failures) | Critical gap: `AuthSystemInstaller::updateAuthConfig()` and `updateUserModel()` use `preg_replace` but do not fall back to manual instructions when the regex produces no change. |
| INST-08 | Zero duplicate env vars or conflicting configs after install | `EnvManager::applyChanges()` checks `str_contains($envContent, 'WORKOS_')` at section level only. Need per-key guard before appending each variable. |
</phase_requirements>

---

## Summary

Phase 3 builds on a solid foundation. The core install infrastructure -- `EnvironmentDetector`, `WizardFlow`, `EnvManager`, `AuthSystemInstaller`, `MigrationPlanGenerator`, and all migration plans -- exists and is tested. The 296-test suite is green.

The three new capabilities this phase adds are: (1) a `NodeToolingDetector` class that probes for `bun`, `npx`, or `pnpm dlx` and delegates to `workos install`/`workos doctor` via `Process::run()`; (2) hardened `--force` mode that bypasses all wizard prompts by reading `$command->option('force')` throughout WizardFlow; and (3) post-write verification for every `preg_replace`-based file edit, with fallback to printed manual instructions when the regex did not match.

**Primary recommendation:** Implement Node detection as a new `NodeToolingDetector` class injected into `InstallCommand::handle()`. Add `--force` bypass via early-returns in WizardFlow confirm calls. Add verification assertions as a helper in each component installer's mutation methods.

---

## Standard Stack

### Core (Already Present)
| Library | Version | Purpose | Status |
|---------|---------|---------|--------|
| `Illuminate\Support\Facades\Process` | Laravel 11+ | Run external shell commands (composer, workos CLI) | Already used in LaravelWorkosMigrator [VERIFIED: in-codebase] |
| `Illuminate\Support\Facades\File` | Laravel 11+ | File read/write/exists for .env, auth.php, User model | [VERIFIED: in-codebase] |
| `Illuminate\Console\Command` | Laravel 11+ | Base class, output helpers, option/confirm/choice | [VERIFIED: in-codebase] |

### Supporting (No New Composer Dependencies Required)
All work can be done with existing PHP + Laravel primitives.

**WorkOS CLI (external, invoked as child process)**
| Tool | Version | Invocation | Notes |
|------|---------|------------|-------|
| `workos` npm package | 0.12.1 | `npx workos@latest install` or `bunx workos@latest install` | [VERIFIED: npm view + `npx workos --help` run in project] |

The WorkOS CLI is NOT a Composer dependency. It is a Node.js CLI invoked via `npx`/`bunx`/`pnpm dlx`.

---

## Architecture Patterns

### New Class: NodeToolingDetector

```
src/
└── Support/
    ├── EnvironmentDetector.php  (exists)
    ├── DetectionResult.php       (exists)
    └── NodeToolingDetector.php   (NEW)
```

`NodeToolingDetector` responsibilities:
1. Probe for available Node package runners in order: `bun` -> `npx` -> `pnpm`
2. Return the preferred runner invocation string or null
3. Run `workos@latest install` and `workos@latest doctor` via `Process::run()`

```php
// Source: derived from CONTEXT.md D-01 + verified Process facade API
final class NodeToolingDetector
{
    public function detect(): ?string  // returns 'bunx', 'npx workos@latest', 'pnpm dlx workos@latest', or null
    public function runInstall(Command $command, string $runner): bool
    public function runDoctor(Command $command, string $runner): void
}
```

### Pattern: Node Runtime Detection via Process

Use `Process::run()` with `command -v` to probe executables. `command -v` is POSIX-standard and available in bash/zsh on macOS/Linux. The project targets macOS/Linux development (confirmed by platform field in env context).

```php
// Source: [VERIFIED: Process facade API] + [ASSUMED: cross-platform edge cases]
use Illuminate\Support\Facades\Process;

private function probeBun(): bool
{
    return Process::run('command -v bun')->successful();
}

private function probeNpx(): bool
{
    return Process::run('command -v npx')->successful();
}
```

Detection order (per CLAUDE.md preference for bun): `bun` -> `npx` -> `pnpm`.

### Verified WorkOS CLI Flags

Run directly against project -- [VERIFIED: direct invocation in project directory]:

`workos install` relevant flags:
- `--api-key` -- WorkOS API key (non-interactive mode)
- `--client-id` -- WorkOS client ID (non-interactive mode)
- `--integration` -- Framework name; `php-laravel` is valid
- `--no-branch` -- Do not create git branch (we manage git)
- `--no-commit` -- Do not auto-commit (we manage commits)
- `--no-validate` -- Skip post-install validation
- `--install-dir` -- Target directory

`workos doctor` relevant flags:
- `--skip-ai` -- Skip AI analysis (requires workos auth login; safe offline)
- `--skip-api` -- Fully offline mode
- `--install-dir` -- Project directory to analyze

### Pattern: --force Bypasses WizardFlow Prompts

Currently `--force` only reaches `vendor:publish`. For INST-03, each confirm method in `WizardFlow` must check `$command->option('force')`:

```php
// Source: [VERIFIED: existing WizardFlow code + Command::option() API]
private function confirmEnvChanges(Command $command, DetectionResult $detection): bool
{
    if ($command->option('force')) {
        return true;
    }
    // existing confirmation logic...
}

private function askComponentSelection(Command $command): array
{
    if ($command->option('force')) {
        return ['routes', 'auth-system', 'webhooks']; // select all
    }
    // existing confirm loop...
}
```

Same pattern applies to: `confirmMigrations` and `askLaravelWorkosStrategy` (auto-select 'replace' when forced).

### Pattern: Post-Write Verification (INST-07)

The current `AuthSystemInstaller` partially handles verification by checking `$result !== null && $result !== $contents`, but does NOT fall back to manual instructions when the regex produced no change. The hardened pattern adds an explicit fallback:

```php
// Source: [VERIFIED: existing AuthSystemInstaller::updateAuthConfig() + hardened]
private function updateAuthConfig(Command $command): void
{
    // ...read file + check for existing guard...

    $result = preg_replace($pattern, $replacement, $contents);

    if ($result === null || $result === $contents) {
        $command->warn('Could not automatically update config/auth.php');
        $command->line('  Please add manually to the guards array:');
        $command->line("      'workos' => ['driver' => 'workos', 'provider' => 'users'],");
        return;
    }

    File::put($authConfigPath, $result);
    $command->info('Updated config/auth.php with WorkOS guard');
}
```

Files requiring hardened verification:
- `AuthSystemInstaller::updateAuthConfig()` -- guards AND providers sections
- `AuthSystemInstaller::addTraitImports()` -- currently returns original on failure (silent)
- `AuthSystemInstaller::addTraitUsages()` -- currently returns original on failure (silent)
- `LaravelWorkosMigrator::removeWorkosFromServices()` -- currently returns original on failure (silent)

### Pattern: EnvManager Per-Key Duplicate Guard (INST-08)

Current `applyChanges()` checks `str_contains($envContent, 'WORKOS_')` at section level. On re-run, it may append a key that already exists. Fix: check per-key before appending:

```php
// Source: [VERIFIED: existing EnvManager::applyChanges() code]
foreach ($changes['add'] as $key => $value) {
    if (str_contains($envContent, "{$key}=")) {
        continue; // already present -- INST-08 re-run safety
    }
    $envContent .= "{$key}={$value}\n";
}
```

### Pattern: --mini Writes Placeholders (D-05)

Current `handleMiniInstall` prints missing env vars but does NOT write them. D-05 requires writing empty placeholder values to `.env`. The `EnvManager` already supports this -- the `--mini` path needs to call `applyChanges()` directly after detection:

```php
// Source: [VERIFIED: existing handleMiniInstall + EnvManager::applyChanges()]
private function handleMiniInstall(DetectionResult $result): int
{
    $this->publishConfig();
    $this->envManager->applyChanges($result);  // ADD: write placeholders
    $this->displayMiniInstructions($result);
    return self::SUCCESS;
}
```

### Anti-Patterns to Avoid

- **Silent regex failure:** Never treat `preg_replace` returning `$contents` unchanged as success. Always check and fall back to manual instructions.
- **WorkOS CLI blocking the terminal:** `workos install` is interactive and AI-powered (30-60 seconds). Use `Process::run()` with an output callback to stream output to the terminal rather than buffering silently.
- **Probing for `node` instead of `npx`/`bun`:** Detect the package runner, not the runtime. `node` present does not mean `npx` works.
- **Passing `--force` to WorkOS CLI:** D-04 specifies `--force` only applies to Laravel config files, not to the WorkOS CLI invocation.
- **Using string concatenation for shell args:** Always pass array arguments to `Process::run()` when variables are involved to prevent shell injection.

---

## Don't Hand-Roll

| Problem | Do Not Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Credential setup / env var writing from WorkOS | Custom prompts asking for API key interactively | `workos install` via Process | WorkOS CLI handles auth, key validation, multiple environments, OAuth login |
| Post-install diagnosis | Custom doctor artisan command | `workos doctor` via Process | WorkOS CLI has AI-powered analysis, API connectivity checks, auth pattern detection |
| Composer package detection | Custom file parsing | `EnvironmentDetector::hasComposerDependency()` (exists) | Already implemented and tested |
| Shell command execution | `exec()` / `shell_exec()` | `Illuminate\Support\Facades\Process` | Testable via `Process::fake()`, handles stderr/stdout and exit codes |

---

## Common Pitfalls

### Pitfall 1: Process::run() Buffers Interactive Output
**What goes wrong:** `Process::run('npx workos@latest install')` captures stdout/stderr but does not pass them to the terminal in real-time. The user sees nothing while the AI-powered installer runs.
**Why it happens:** `Process::run()` buffers output by default.
**How to avoid:** Pass an output callback: `Process::run($cmd, fn($type, $out) => $command->getOutput()->write($out))`. For commands requiring TTY input, use `Process::tty()->run()`.
**Warning signs:** Process completes but user sees no output during execution.

### Pitfall 2: --force Does Not Currently Bypass WizardFlow Confirms
**What goes wrong:** Running `workos:install --force` still prompts for component selection, env confirmation, and migration confirmation.
**Why it happens:** `WizardFlow::run()` never reads `$command->option('force')`.
**How to avoid:** Add early-return checks in each confirm/choice method inside WizardFlow.
**Warning signs:** `$this->artisan('workos:install --force')` in tests triggers `expectsConfirmation()`.

### Pitfall 3: preg_replace Returns Original on Pattern Mismatch
**What goes wrong:** `preg_replace($pattern, $replacement, $contents)` returns the original string when the pattern does not match (not null, not false). Code that checks only `$result !== null` misidentifies this as success.
**Why it happens:** Normal PCRE behavior -- no match = return original input.
**How to avoid:** Always check `$result !== null && $result !== $contents` before writing.

### Pitfall 4: WorkOS CLI doctor Requires Auth for AI Analysis
**What goes wrong:** `workos doctor` without `workos auth login` outputs "Not authenticated" for AI analysis section. Other checks still run.
**Why it happens:** AI analysis requires a WorkOS account session separate from the API key.
**How to avoid:** Always pass `--skip-ai` when running doctor programmatically. Basic checks (SDK, env vars, API connectivity) run without auth. [VERIFIED: running `npx workos doctor` in project]

### Pitfall 5: EnvManager Appends Duplicate Keys on Re-run
**What goes wrong:** Running `workos:install` twice with partial env vars present causes duplicate key entries in `.env`.
**Why it happens:** Current `applyChanges()` checks at section level (`str_contains($envContent, 'WORKOS_')`), not per-key.
**How to avoid:** Add per-key guard before each append operation.

### Pitfall 6: WorkOS CLI install May Modify Laravel Files
**What goes wrong:** `workos install --integration php-laravel` generates auth routes and modifies framework files, potentially conflicting with our AuthSystemInstaller.
**Why it happens:** The CLI does framework-specific code generation for Laravel.
**How to avoid:** Use WorkOS CLI only for env/credential setup. Our installer handles all Laravel-specific config. If delegation scope is unclear, test in an isolated blank Laravel app first.

---

## Code Examples

### Process Facade with Output Streaming
```php
// Source: [VERIFIED: Illuminate\Support\Facades\Process API]
use Illuminate\Support\Facades\Process;

$result = Process::run(
    ['npx', 'workos@latest', 'install', '--integration', 'php-laravel', '--no-branch', '--no-commit'],
    function (string $type, string $output) use ($command): void {
        $command->getOutput()->write($output);
    }
);

if (! $result->successful()) {
    $command->warn('WorkOS CLI install failed -- continuing with Laravel config only');
    $command->line('  Run manually: npx workos@latest install');
}
```

### Process::fake() for Testing
```php
// Source: [VERIFIED: Laravel Process::fake() API]
Process::fake([
    'command -v bun' => Process::result('', '', 0),
    '*workos*install*' => Process::result('WorkOS installed', '', 0),
    '*workos*doctor*' => Process::result('No issues found', '', 0),
]);
```

### WizardFlow --force Bypass
```php
// Source: [VERIFIED: existing WizardFlow + Command::option() API]
private function askComponentSelection(Command $command): array
{
    if ($command->option('force')) {
        return ['routes', 'auth-system', 'webhooks'];
    }
    // existing confirm loop...
}
```

### Post-Write Verification Helper
```php
// Source: [VERIFIED: pattern from existing AuthSystemInstaller, hardened]
private function applyRegexOrPrintManual(
    Command $command,
    string $filePath,
    string $pattern,
    string $replacement,
    string $manualInstructions
): bool {
    $contents = File::get($filePath);
    $result = preg_replace($pattern, $replacement, $contents);

    if ($result === null || $result === $contents) {
        $command->warn("Could not automatically update {$filePath}");
        $command->line($manualInstructions);
        return false;
    }

    File::put($filePath, $result);
    return true;
}
```

---

## WorkOS CLI: Verified Behavior

Verified by running `npx workos@latest` commands in the project directory [VERIFIED: direct invocation]:

| Command | Key Flags | Behavior |
|---------|-----------|----------|
| `workos install` | `--api-key`, `--client-id`, `--integration php-laravel`, `--no-branch`, `--no-commit`, `--no-validate`, `--install-dir` | AI-powered, interactive, handles SDK install + env vars + framework files |
| `workos doctor` | `--skip-ai`, `--skip-api`, `--json`, `--verbose`, `--install-dir` | Detects SDK (workos-php), package manager (composer), API connectivity, auth patterns. Works without auth when `--skip-ai` passed. |

`workos doctor` output in this project (no API key set): SDK=workos-php, Language=PHP, Package Manager=composer, API reachable, 5 auth pattern checks passed, 2 critical issues (MISSING_API_KEY, MISSING_CLIENT_ID).

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `command -v bun` / `command -v npx` work reliably in PHP child process environment on macOS | Node Runtime Detection | Detection fails silently; mitigation: probe `bun --version` instead (also fast, more reliable) |
| A2 | WorkOS CLI `--integration php-laravel` with `--no-branch --no-commit` does not conflict with our AuthSystemInstaller file modifications | WorkOS CLI Integration | Double-modification of auth.php or User model; mitigation: our idempotency checks (str_contains guards) catch duplicates |
| A3 | Windows compatibility not required (project targets macOS/Linux per env context) | Node Runtime Detection | LOW risk -- confirmed by platform: darwin in env |

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| Node.js | WorkOS CLI delegation (D-01) | Yes | v24.12.0 | Fall back to EnvManager (D-02) |
| npx | WorkOS CLI via npm | Yes | 11.11.0 | Use bun or pnpm dlx |
| bun | WorkOS CLI via bun (preferred) | Yes | 1.3.0 | Use npx |
| pnpm | WorkOS CLI via pnpm | Yes | 8.15.8 | Use bun or npx |
| PHP 8.3 | Package constraint | Yes | per composer.json | n/a |
| Pest PHP | Test framework | Yes | ^3.0 (root) | n/a |

[VERIFIED: `command -v` checks run on dev machine]

All Node runners available on dev machine. Detection order: `bun` -> `npx` -> `pnpm` (per CLAUDE.md bun preference).

---

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest PHP ^3.0 |
| Config file | `phpunit.xml` |
| Quick run command | `composer test -- --filter "InstallCommand"` |
| Full suite command | `composer test` |
| Baseline | 296 tests, 683 assertions [VERIFIED: run in session] |

### Phase Requirements to Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| INST-01 | Detects Breeze/Jetstream/Fortify/laravel-workos from composer.json | Unit | `composer test -- --filter "EnvironmentDetectorTest"` | Yes |
| INST-02 | Wizard prompts component selection, runs selected installers | Feature | `composer test -- --filter "InstallCommandTest"` | Yes |
| INST-03 | `--force` bypasses all wizard prompts and installs all components | Feature | `composer test -- --filter "InstallCommandTest"` | Yes (needs new cases) |
| INST-04 | `--mini` publishes config and writes placeholder env vars to .env | Feature | `composer test -- --filter "InstallCommandTest"` | Yes (needs new env-write case) |
| INST-05 | laravel/workos services.php config removed during migration | Unit | `composer test -- --filter "LaravelWorkosMigratorTest"` | No -- Wave 0 gap |
| INST-06 | Migration plan printed to console AND written to storage/ | Feature | `composer test -- --filter "InstallCommandTest"` | Yes (needs --mini path case) |
| INST-07 | Failed regex edit falls back to manual instructions, not silent skip | Unit | `composer test -- --filter "AuthSystemInstallerTest"` | No -- Wave 0 gap |
| INST-08 | Re-run produces no duplicate env vars or guard entries | Feature | `composer test -- --filter "InstallCommandTest"` | Yes (needs re-run scenario) |

### Sampling Rate
- **Per task commit:** `composer test -- --filter "InstallCommand|WizardFlow|EnvManager|AuthSystemInstaller|NodeTooling"`
- **Per wave merge:** `composer test`
- **Phase gate:** Full suite green (296+ tests) before `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `tests/Unit/LaravelWorkosMigratorTest.php` -- covers INST-05 (services.php extraction regex verification)
- [ ] `tests/Unit/AuthSystemInstallerTest.php` -- covers INST-07 (post-write verification fallback when preg_replace produces no change)
- [ ] `tests/Unit/NodeToolingDetectorTest.php` -- covers Node detection probing with `Process::fake()`
- [ ] Add `DetectionResultFactory::withServicesWorkosConfig()` helper if needed for migrator tests

---

## Security Domain

`security_enforcement` absent from config.json -- treating as enabled.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | No | Install command, not runtime auth |
| V3 Session Management | No | Not applicable |
| V4 Access Control | No | Not applicable |
| V5 Input Validation | Yes | Env var values written to .env via File::put() -- string-typed, no injection risk |
| V6 Cryptography | No | Not applicable |

### Known Threat Patterns for Install Commands

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Shell injection in Process::run() | Tampering | Use array arguments `['npx', 'workos@latest', 'install']` rather than string interpolation when any variable is included |
| Overwriting auth.php with malformed content | Tampering | preg_replace inserts valid PHP syntax; verify regex does not match unexpected sections |
| .env written world-readable | Information Disclosure | File::put() inherits umask; standard .env is 644 -- acceptable for local dev |

---

## Open Questions

1. **WorkOS CLI install interactivity level for Laravel**
   - What we know: CLI is AI-powered, interactive; supports `--api-key` and `--client-id` for non-interactive mode
   - What is unclear: Whether `--api-key --client-id --integration php-laravel --no-branch --no-commit` runs fully non-interactively without any stdin prompts
   - Recommendation: Test in isolation with flags before assuming non-interactive. Fallback (D-02): skip WorkOS CLI and use EnvManager directly.

2. **Exact files `workos install --integration php-laravel` creates or modifies**
   - What we know: CLI generates "auth routes, middleware, env vars, SDK installation, UI components"
   - What is unclear: Whether it modifies auth.php or creates controllers -- potential conflict with AuthSystemInstaller
   - Recommendation: Test in a blank Laravel app before assuming no conflict. Safest boundary: use WorkOS CLI for env/credential setup only.

---

## Sources

### Primary (HIGH confidence)
- Direct CLI invocation: `npx workos@latest --help`, `npx workos@latest install --help`, `npx workos@latest doctor --help`, `npx workos@latest doctor` -- all run in project directory, output captured
- In-codebase: `src/Install/AuthSystemInstaller.php`, `src/Install/WizardFlow.php`, `src/Install/LaravelWorkosMigrator.php`, `src/Install/EnvManager.php`, `src/Support/EnvironmentDetector.php` -- read directly
- Test suite: `composer test` -- 296 tests, 683 assertions green, establishes baseline

### Secondary (MEDIUM confidence)
- `https://github.com/workos/cli` (via WebFetch): Laravel (`php-laravel`) is explicitly listed as supported integration

### Tertiary (LOW confidence)
- Assumption A1: `command -v` reliability in PHP child process on macOS -- widely used pattern but not formally verified in this session

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH -- all libraries in-codebase, Process facade API verified, CLI flags verified
- Architecture: HIGH -- patterns derived from existing code + verified CLI behavior
- Pitfalls: HIGH -- identified from direct code reading + CLI testing; one LOW item (A1)

**Research date:** 2026-04-06
**Valid until:** 2026-07-06 (WorkOS CLI at 0.12.1; re-verify flags if planning after this date)
