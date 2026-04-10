# Smart Install Contract

**Created**: 2026-01-23
**Confidence Score**: 90/100
**Status**: Draft

## Problem Statement

The current `workos:install` command is one-size-fits-all and doesn't account for the variety of ways developers arrive at installing authkit-laravel. When Laravel 12 shipped, it introduced WorkOS as a first-class authentication option during `laravel new`, using the official `laravel/workos` package. This creates four distinct starting points that the install command must handle intelligently:

1. **Fresh Laravel with no auth** - Simplest case, full control
2. **Fresh Laravel with built-in auth (Breeze/Jetstream/Fortify)** - Needs migration assistance
3. **Fresh Laravel that chose WorkOS during `laravel new`** - Has `laravel/workos` installed with config in `services.php` and `WORKOS_REDIRECT_URL` env var
4. **Existing production app** - Most complex, migrating from another auth system

The current installer doesn't detect existing state, can create duplicate configurations, and provides no migration guidance. This leads to confused developers, support requests, and abandoned installations.

## Goals

1. **Intelligent Detection**: Automatically detect the user's starting point (no auth, built-in auth, laravel/workos, or existing app) and adapt the installation flow accordingly
2. **Three Installation Modes**: Support `--force` (overwrite all), wizard (interactive, default), and `--mini` (config + docs link only) to serve different use cases
3. **Seamless Migration from laravel/workos**: Detect existing config in `services.php`, offer to migrate to `workos.php`, handle env var renaming (`WORKOS_REDIRECT_URL` → `WORKOS_REDIRECT_URL` - keep Laravel's convention)
4. **Migration Assistant for Existing Apps**: Detect Breeze/Jetstream/Fortify, generate a migration plan, and optionally assist with data migration
5. **Zero Duplicate Configuration**: Never leave the user with conflicting configs or duplicate env vars

## Success Criteria

- [ ] Running `workos:install` on a fresh Laravel app with no auth completes successfully with full configuration
- [ ] Running `workos:install` on an app that selected WorkOS during `laravel new` detects existing setup and offers migration options
- [ ] Running `workos:install --force` overwrites all existing auth configuration without prompting
- [ ] Running `workos:install --mini` publishes only config file and displays setup instructions (no migrations auto-run)
- [ ] Wizard mode interactively asks which components to install: Routes, Full auth system, Webhooks
- [ ] When `laravel/workos` is detected, wizard asks whether to replace it, augment it, or keep both
- [ ] Existing `WORKOS_REDIRECT_URL` in `.env` is preserved (not duplicated or renamed)
- [ ] Detection of Breeze/Jetstream/Fortify generates appropriate migration guidance
- [ ] All three modes result in a working authentication flow with no manual fixups required
- [ ] Install command has comprehensive test coverage for all scenarios

## Scope Boundaries

### In Scope

- Detection logic for: no auth, laravel/workos, Breeze, Jetstream, Fortify
- Three installation modes: `--force`, wizard (default), `--mini`
- Interactive wizard flow with component selection (Routes, Full auth system, Webhooks)
- Config migration from `services.php` to `workos.php` when laravel/workos detected
- Env var handling (prefer `WORKOS_REDIRECT_URL` to match Laravel convention)
- Migration assistant that detects existing auth and provides actionable guidance
- Auth guard and provider configuration in `auth.php`
- Automatic migration running in wizard mode (with confirmation in mini mode)
- Comprehensive tests for all installation scenarios

### Out of Scope

- UI scaffolding (views/components) - WorkOS hosts auth UI externally
- Automatic data migration (too risky without user control) - provide guidance only
- Supporting Laravel < 11 (focus on Laravel 11+/12+)
- React/Vue/Livewire-specific integrations - backend only

### Future Considerations

- Optional dashboard view scaffolding (phase 2)
- `workos:upgrade` command for migrating between authkit-laravel versions
- `workos:doctor` command to diagnose common configuration issues
- Integration with Laravel Herd/Valet for local HTTPS setup guidance

---

*This contract was generated from brain dump input. Review and approve before proceeding to PRD generation.*
