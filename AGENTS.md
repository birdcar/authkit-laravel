# Authkit Laravel

This repository is a Laravel package. Keep the package focused, idiomatic, and easy for Laravel developers to install, test, and maintain.

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape before adding abstractions.
- Keep package names, namespaces, Composer metadata, publish tags, documentation, and examples aligned with `birdcar/authkit-laravel`.
- Add only the files and dependencies needed for the package behavior being implemented.
- Prefer explicit Laravel package code over helper abstractions unless the extension point is real.
- Keep tests focused on observable package behavior through public APIs, service provider wiring, commands, routes, published resources, and documentation promises.

## Quick Commands

- Baseline verification: `./init.sh` (composer install + full validation)
- Full validation: `composer test`
- Formatting check: `composer lint:check`
- Static analysis: `composer analyse`
- Pest tests: `composer test:unit`
- Workbench build: `composer build`
- Workbench server: `composer serve`

## Local Skills

- `package-scaffold`: use when adding package capabilities or wiring them through the service provider, including commands, migrations, routes, config, views, translations, assets, middleware, publish tags, workbench files, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 4 and Orchestra Testbench.
- `package-release`: use when preparing changelog, release notes, tags, or GitHub release workflow changes.
- `package-compatibility`: use when reviewing code, dependencies, or CI against the PHP and Laravel support matrix.
- `package-generate-skill`: use when updating the bundled Boost skill from the package implementation, README, and examples.

## Agent Harness

Startup, state, and handoff files for agent sessions live at the repo root:

- `feature_list.json` — feature state tracker (source of truth for what to work on)
- `progress.md` — session continuity log
- `session-handoff.md` — end-of-session handoff for multi-session work
- `init.sh` — baseline verification (`composer install` + `composer test`)

### Startup Workflow

Before writing code:

1. Read this file completely.
2. Run `./init.sh` to verify the baseline (`composer test` chains `analyse`, `lint:check`, `test:types`, and `test:unit`).
3. Read `feature_list.json` for current feature state and `progress.md` for session context.
4. Review recent commits with `git log --oneline -5`.

If baseline verification fails, repair that before adding new scope.

### Working Rules

- One feature at a time: work on exactly one unfinished feature from `feature_list.json`.
- Never claim done without running `composer test` (or `./init.sh` from a clean state).
- Stay in scope: don't modify files unrelated to the current feature.

### Definition of Done

A feature is done only when all of the following are true:

- Target behavior is implemented.
- `composer test` passed and the evidence is recorded in `feature_list.json` or `progress.md`.
- The repo remains restartable via `./init.sh`.

### End of Session

1. Update `progress.md` with current state and `feature_list.json` with feature status.
2. Fill in `session-handoff.md` for multi-session work, including verification evidence.
3. Record unresolved risks or blockers.
4. Commit once work is in a safe state; leave the repo clean enough for the next session to run `./init.sh` immediately.
