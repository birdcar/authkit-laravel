# Packagist Release Pipeline Contract

**Created**: 2026-04-08
**Confidence Score**: 97/100
**Status**: Approved
**Supersedes**: None

## Problem Statement

The `birdcar/authkit-laravel` package requires users to manually add a VCS repository entry to their `composer.json` before installing. This friction discourages adoption — Laravel developers expect `composer require` to just work via Packagist.

The release pipeline uses a custom `birdcar/actions/auto-release` action with label-driven versioning. This works but is non-standard — release-please is the industry standard for conventional-commit-driven releases, produces a release PR for review before shipping, and is maintained by Google.

CI runs package-level tests but not the workbench app's 22 Pest tests, and there's no coverage reporting or automated dependency updates.

## Goals

1. Package installable via `composer require birdcar/authkit-laravel` from Packagist
2. Releases automated via release-please (conventional commits → Release PR → GitHub Release → Packagist auto-sync)
3. Workbench app tests run in CI alongside package tests
4. Code coverage reported to Codecov with badge in README
5. Dependabot configured for automated dependency update PRs

## Success Criteria

- [ ] `composer require birdcar/authkit-laravel` installs from Packagist without VCS config
- [ ] Pushing a `feat:` or `fix:` commit to main creates a release-please PR with version bump and CHANGELOG
- [ ] Merging a release-please PR creates a GitHub Release and Packagist auto-syncs the new version
- [ ] CI runs workbench Pest tests (22 tests) in addition to package tests
- [ ] Codecov badge in README shows coverage percentage
- [ ] Dependabot opens PRs for outdated Composer and npm dependencies
- [ ] Old `birdcar/actions/auto-release` workflow is removed

## Scope Boundaries

### In Scope

- Register package on packagist.org (manual step, documented in spec)
- Configure GitHub → Packagist webhook for auto-sync
- Replace `release.yml` with release-please workflow
- Add `release-please-config.json` and `.release-please-manifest.json`
- Add workbench test job to `ci.yml`
- Add Codecov coverage upload to CI
- Add `.github/dependabot.yml`
- Update README: install instructions, Codecov badge, remove VCS repo requirement
- Remove reference to `birdcar/actions/auto-release`

### Out of Scope

- New package features — this is infrastructure only
- Packagist paid plans — free tier is sufficient
- GitHub Packages registry — Packagist is the standard for PHP
- Branch protection rules — user manages these manually

### Future Considerations

- Security policy (SECURITY.md)
- Issue templates
- PR templates
- Stale issue automation

## Execution Plan

### Dependency Graph

```
Phase 1: All changes (single phase — <10 files)
```

### Execution Steps

**Strategy**: Sequential (single phase)

1. **Manual prerequisite**: Register on packagist.org and add GitHub webhook
2. **Phase 1** — CI/CD pipeline and Packagist publishing
   ```bash
   /execute-spec docs/ideation/packagist-release-pipeline/spec.md
   ```

---

_This contract was generated from brain dump input. Review and approve before proceeding to specification._
