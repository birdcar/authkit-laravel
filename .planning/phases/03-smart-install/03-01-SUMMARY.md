---
plan: 03-01
phase: 03-smart-install
status: complete
started: 2026-04-06
completed: 2026-04-06
---

# Plan 03-01: NodeToolingDetector + WorkOS CLI Integration

## What Was Built

Created `NodeToolingDetector` class that probes for Node.js package runners (bun → npx → pnpm) and integrated it into `InstallCommand` to delegate env/credential setup to the WorkOS CLI when Node tooling is available.

## Key Files

### Created
- `src/Support/NodeToolingDetector.php` — Detects bun/npx/pnpm, runs `workos install` and `workos doctor`
- `tests/Unit/NodeToolingDetectorTest.php` — 14 unit tests covering detection, install, doctor, and edge cases

### Modified
- `src/Commands/InstallCommand.php` — Added NodeToolingDetector constructor injection, delegation before wizard/mini flows, doctor post-install suggestion
- `tests/Feature/InstallCommandTest.php` — 3 new integration tests for CLI delegation
- `tests/Pest.php` — Added `cmdStr()`/`cmdArray()` shared test helpers

## Deviations

- Symfony Process array-arg escaping format required adjustment for fake key matching in tests
- Shared helper functions moved to `tests/Pest.php` bootstrap to avoid redeclaration across test files

## Self-Check

All 14 NodeToolingDetector tests pass. 3 integration tests pass. PHPStan clean. Pint clean.
