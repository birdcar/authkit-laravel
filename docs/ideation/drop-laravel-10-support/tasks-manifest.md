# Tasks Manifest: Drop Laravel 10 Support

**Created:** 2025-01-27
**Project:** drop-laravel-10-support
**Complexity:** Low (single phase, 3 files)

## Quick Start

Execute the spec:
```bash
/execute-spec docs/ideation/drop-laravel-10-support/spec-phase-1.md
```

Or have an agent implement it:
```
Use the laravel-simplifier agent to implement spec-phase-1.md and simplify the changes
```

## Phases

| Phase | Status | Spec File | Description |
|-------|--------|-----------|-------------|
| 1 | pending | spec-phase-1.md | Remove Laravel 10 support from all files |

## Files to Modify

1. `composer.json` - Update dependency constraints
2. `src/WorkOSServiceProvider.php` - Remove compatibility shim
3. `.github/README.md` - Update requirements list

## Verification Checklist

- [ ] `composer validate` passes
- [ ] `composer update` completes without errors
- [ ] `composer test` passes
- [ ] `composer analyse` passes
- [ ] No "Laravel 10" references remain (grep -r "Laravel 10" .)
