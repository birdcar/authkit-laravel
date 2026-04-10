# Implementation Spec: Packagist Release Pipeline

**Contract**: ./contract.md
**Estimated Effort**: S (small — config files and README edits)

## Technical Approach

Replace the custom `birdcar/actions/auto-release` label-driven workflow with Google's `release-please` action. Release-please reads conventional commit messages (feat:, fix:, chore:, etc.), auto-generates a Release PR with version bump and CHANGELOG updates, and creates a GitHub Release on merge. Packagist auto-syncs via webhook when a new tag is pushed.

Add workbench Pest tests to the CI matrix, upload coverage to Codecov, and configure Dependabot for Composer + npm dependency updates.

## Feedback Strategy

**Inner-loop command**: `composer test && composer analyse`

**Playground**: CI workflow files are validated by pushing and observing GitHub Actions runs. Local validation uses `act` if available, or syntax checking via `actionlint`.

**Why this approach**: Most changes are YAML config and markdown — the real validation happens on GitHub Actions after push.

## File Changes

### New Files

| File Path | Purpose |
|-----------|---------|
| `.release-please-manifest.json` | Tracks current version for release-please |
| `release-please-config.json` | Configures release-please behavior (package type, changelog, etc.) |
| `.github/dependabot.yml` | Automated dependency update PRs for Composer and npm |

### Modified Files

| File Path | Changes |
|-----------|---------|
| `.github/workflows/release.yml` | Replace auto-release with release-please action |
| `.github/workflows/ci.yml` | Add workbench test job and Codecov coverage upload |
| `.github/README.md` | Update install instructions (remove VCS repo), add Codecov badge |
| `composer.json` | Add `pcov` to suggest for coverage (optional) |

### Deleted Files

None — the old `release.yml` is modified in place, not deleted.

## Implementation Details

### 1. Release-Please Configuration

**Overview**: Configure release-please for a PHP Composer package with Keep a Changelog format.

`release-please-config.json`:
```json
{
  "$schema": "https://raw.githubusercontent.com/googleapis/release-please/main/schemas/config.json",
  "packages": {
    ".": {
      "release-type": "php",
      "changelog-type": "default",
      "bump-minor-pre-major": true,
      "bump-patch-for-minor-pre-major": true,
      "include-component-in-tag": false,
      "include-v-in-tag": true
    }
  }
}
```

`.release-please-manifest.json`:
```json
{
  ".": "0.1.0"
}
```

**Key decisions**:
- `release-type: php` — release-please knows to update `composer.json` version field on release
- `bump-minor-pre-major: true` — breaking changes bump minor (not major) while pre-1.0
- `include-v-in-tag: true` — tags as `v1.0.0` matching existing convention

**Implementation steps**:
1. Create `release-please-config.json` at repo root
2. Create `.release-please-manifest.json` at repo root with current version `0.1.0`

### 2. Release Workflow (release-please)

**Pattern to follow**: [release-please GitHub Action docs](https://github.com/googleapis/release-please-action)

**Overview**: Replace `birdcar/actions/auto-release` with `googleapis/release-please-action`.

`.github/workflows/release.yml`:
```yaml
name: Release

on:
  push:
    branches: [main]

permissions:
  contents: write
  pull-requests: write

jobs:
  release-please:
    runs-on: ubuntu-latest
    steps:
      - uses: googleapis/release-please-action@v4
        with:
          token: ${{ secrets.GITHUB_TOKEN }}
```

**Key decisions**:
- Minimal config — release-please reads `release-please-config.json` for everything else
- `pull-requests: write` permission required for release-please to create/update the Release PR
- No need for label config — release-please uses conventional commits, not PR labels

**Implementation steps**:
1. Replace entire contents of `.github/workflows/release.yml`
2. Verify `permissions` includes both `contents: write` and `pull-requests: write`

### 3. CI Workflow Enhancements

**Pattern to follow**: `.github/workflows/ci.yml` (existing)

**Overview**: Add workbench test job and Codecov coverage upload.

Add a `workbench-tests` job:
```yaml
  workbench-tests:
    runs-on: ubuntu-latest
    name: Workbench Tests

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: mbstring, xml, ctype, json, curl, sqlite3
          coverage: pcov

      - name: Install dependencies
        run: |
          composer install --prefer-dist --no-interaction
          cd workbench && composer install --prefer-dist --no-interaction

      - name: Run workbench tests
        run: cd workbench && php artisan test

      - name: Run workbench tests with coverage
        run: cd workbench && php artisan test --coverage --min=80 --coverage-clover=coverage.xml

      - name: Upload coverage to Codecov
        uses: codecov/codecov-action@v4
        with:
          file: workbench/coverage.xml
          token: ${{ secrets.CODECOV_TOKEN }}
          fail_ci_if_error: false
```

**Key decisions**:
- Workbench tests run on PHP 8.4 only (not full matrix — they test app behavior, not cross-version compat)
- Coverage uses `pcov` (faster than Xdebug for coverage-only)
- `fail_ci_if_error: false` on Codecov — don't fail CI if Codecov is down
- SQLite3 extension added for workbench database

**Implementation steps**:
1. Add `workbench-tests` job to `ci.yml` after the existing `code-style` job
2. Ensure `sqlite3` extension is in the setup-php `extensions` list

### 4. Dependabot Configuration

**Overview**: Automated PRs for outdated Composer and npm dependencies.

`.github/dependabot.yml`:
```yaml
version: 2
updates:
  - package-ecosystem: "composer"
    directory: "/"
    schedule:
      interval: "weekly"
    labels:
      - "dependencies"

  - package-ecosystem: "github-actions"
    directory: "/"
    schedule:
      interval: "weekly"
    labels:
      - "dependencies"
```

**Key decisions**:
- Weekly schedule — not too noisy, stays current
- Composer + GitHub Actions ecosystems (no npm — workbench frontend deps are dev-only)
- `dependencies` label for easy filtering

**Implementation steps**:
1. Create `.github/dependabot.yml`

### 5. README Updates

**Pattern to follow**: `.github/README.md` (existing)

**Overview**: Remove VCS repo requirement from install instructions, add Codecov badge.

**Changes**:

1. **Add Codecov badge** (line 4, after CI badge):
```markdown
[![codecov](https://codecov.io/gh/birdcar/authkit-laravel/graph/badge.svg)](https://codecov.io/gh/birdcar/authkit-laravel)
```

2. **Replace installation section** — remove the "Install from GitHub" VCS repo block. Replace with:
```markdown
## Installation

```bash
composer require birdcar/authkit-laravel
```

### Run Installation Command

```bash
php artisan workos:install
```
```

3. **Remove** the "Or to install a specific release: `composer require birdcar/authkit-laravel:v0.1.0`" block — Packagist handles version resolution.

**Implementation steps**:
1. Add Codecov badge after CI badge
2. Replace "Install from GitHub" section with simple `composer require`
3. Remove VCS repository JSON block and dev-main/version-specific install instructions

### 6. Packagist Registration (Manual)

**Overview**: One-time manual step — cannot be automated.

**Steps**:
1. Go to https://packagist.org/packages/submit
2. Enter repository URL: `https://github.com/birdcar/authkit-laravel`
3. Submit — Packagist reads `composer.json` and registers the package
4. In the Packagist package settings, enable GitHub webhook auto-sync (Packagist provides the webhook URL and secret)
5. In GitHub repo Settings → Webhooks, add the Packagist webhook
6. Verify: visit `https://packagist.org/packages/birdcar/authkit-laravel` — package should be listed

After webhook is set, every GitHub push triggers Packagist to re-read tags and update available versions.

## Testing Requirements

### Manual Testing

- [ ] Push a conventional commit (`feat: add X`) to main → verify release-please creates a Release PR
- [ ] Merge the Release PR → verify GitHub Release created with CHANGELOG, git tag pushed
- [ ] Verify Packagist shows the new version within minutes of tag push
- [ ] Open a PR → verify workbench-tests job runs and passes
- [ ] Verify Codecov badge shows coverage percentage in README
- [ ] Wait for Dependabot schedule → verify dependency update PRs appear

## Error Handling

| Error Scenario | Handling Strategy |
|----------------|-------------------|
| Codecov upload fails | `fail_ci_if_error: false` — CI still passes |
| Release-please can't parse commits | Release PR not created — user reviews commit format |
| Packagist webhook fails | Manual sync via Packagist dashboard "Update" button |
| Workbench tests fail in CI | CI fails — same behavior as package tests |

## Failure Modes

| Component | Failure Mode | Trigger | Impact | Mitigation |
|-----------|-------------|---------|--------|------------|
| release-please | No Release PR created | Non-conventional commit messages | No release happens | Document conventional commit format in CONTRIBUTING |
| release-please | Wrong version bump | Missing `!` for breaking change | Minor instead of major bump | Pre-1.0: `bump-minor-pre-major` handles this |
| Packagist webhook | Stale package listing | Webhook secret rotated or endpoint changed | Users install old version | Manual "Update" in Packagist dashboard |
| Codecov | Missing coverage data | Token not set in repo secrets | Badge shows "unknown" | Add `CODECOV_TOKEN` to repo secrets |

## Validation Commands

```bash
# Verify release-please config is valid JSON
cat release-please-config.json | python3 -m json.tool

# Verify manifest is valid JSON
cat .release-please-manifest.json | python3 -m json.tool

# Verify CI workflow syntax (if actionlint installed)
actionlint .github/workflows/ci.yml .github/workflows/release.yml

# Run package tests
composer test

# Run workbench tests
cd workbench && php artisan test

# Run workbench tests with coverage
cd workbench && php artisan test --coverage --min=80

# Static analysis
composer analyse

# Code style
composer format:test
```

## Rollout Considerations

- **Packagist registration**: Do this BEFORE merging the README changes — otherwise users see `composer require` instructions that don't work yet
- **Codecov token**: Add `CODECOV_TOKEN` secret to the GitHub repo before merging CI changes
- **Release-please first run**: The first push after replacing the workflow will create an initial Release PR. Don't be surprised by a large PR — it contains all unreleased changes.
- **Rollback plan**: Revert `release.yml` to restore `birdcar/actions/auto-release` if release-please doesn't work as expected

## Open Items

- [ ] Register package on packagist.org (manual — requires account login)
- [ ] Add `CODECOV_TOKEN` to GitHub repo secrets
- [ ] Set up Packagist webhook in GitHub repo settings

---

_This spec is ready for implementation. Follow the patterns and validate at each step._
