# Implementation Spec: AuthKit Laravel Enhancement - Phase 1

**PRD**: ./prd-phase-1.md
**Estimated Effort**: S (Small)

## Technical Approach

This phase establishes CI/CD automation using GitHub Actions. The CI workflow will run on PRs and main branch pushes, executing the test suite across a matrix of PHP and Laravel versions. The release workflow uses the `birdcar/actions/auto-release` action to automate versioning based on PR labels.

The key technical decisions are:
1. Use GitHub Actions matrix strategy to test all PHP/Laravel combinations
2. Cache Composer dependencies for faster CI runs
3. Use the existing composer scripts (`test`, `analyse`, `format:test`) for consistency
4. Configure auto-release with standard label conventions (major, minor, patch)

No spikes or research needed - this is standard GitHub Actions configuration following the birdcar/actions pattern.

## File Changes

### New Files

| File Path | Purpose |
|-----------|---------|
| `.github/workflows/ci.yml` | CI workflow for testing PRs and pushes |
| `.github/workflows/release.yml` | Release workflow using birdcar/actions/auto-release |
| `CHANGELOG.md` | Changelog file (auto-updated by release action) |

### Modified Files

| File Path | Changes |
|-----------|---------|
| `.gitattributes` | Add distribution exclusions for tests, workbench, etc. |

### Deleted Files

None.

## Implementation Details

### CI Workflow

**Pattern to follow**: Standard GitHub Actions PHP matrix workflow

**Overview**: Multi-version CI that tests PHP 8.2-8.4 with Laravel 10-12.

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  tests:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ['8.2', '8.3', '8.4']
        laravel: ['10.*', '11.*', '12.*']
        exclude:
          # Laravel 12 requires PHP 8.2+
          # No exclusions needed - all combos valid

    name: PHP ${{ matrix.php }} - Laravel ${{ matrix.laravel }}

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: mbstring, xml, ctype, json, curl
          coverage: none

      - name: Get Composer cache directory
        id: composer-cache
        run: echo "dir=$(composer config cache-files-dir)" >> $GITHUB_OUTPUT

      - name: Cache Composer dependencies
        uses: actions/cache@v4
        with:
          path: ${{ steps.composer-cache.outputs.dir }}
          key: ${{ runner.os }}-php-${{ matrix.php }}-laravel-${{ matrix.laravel }}-${{ hashFiles('**/composer.json') }}
          restore-keys: |
            ${{ runner.os }}-php-${{ matrix.php }}-laravel-${{ matrix.laravel }}-

      - name: Install dependencies
        run: |
          composer require "illuminate/support:${{ matrix.laravel }}" --no-interaction --no-update
          composer update --prefer-dist --no-interaction

      - name: Run tests
        run: composer test

  static-analysis:
    runs-on: ubuntu-latest
    name: Static Analysis

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none

      - name: Install dependencies
        run: composer install --prefer-dist --no-interaction

      - name: Run PHPStan
        run: composer analyse

  code-style:
    runs-on: ubuntu-latest
    name: Code Style

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none

      - name: Install dependencies
        run: composer install --prefer-dist --no-interaction

      - name: Check code style
        run: composer format:test
```

**Key decisions**:
- Use `fail-fast: false` so all matrix jobs complete even if one fails
- Run static analysis and code style as separate jobs for parallelism
- Use PHP 8.3 for static analysis (stable, well-supported)

**Implementation steps**:
1. Create `.github/workflows/ci.yml` with the workflow above
2. Test locally with `act` if available, or push a test branch

### Release Workflow

**Pattern to follow**: `birdcar/actions/auto-release` documentation

**Overview**: Automates releases when PRs merge to main, using labels to determine version bump.

```yaml
name: Release

on:
  push:
    branches: [main]

permissions:
  contents: write

jobs:
  release:
    runs-on: ubuntu-latest
    name: Create Release

    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0

      - name: Auto Release
        uses: birdcar/actions/auto-release@main
        with:
          githubToken: ${{ secrets.GITHUB_TOKEN }}
          changelogPath: CHANGELOG.md
          timezone: America/Chicago
          defaultBump: patch
          majorLabels: major,breaking
          minorLabels: minor,feature,enhancement
          patchLabels: patch,fix,bugfix,bug
          skipLabels: skip-release,no-release
```

**Key decisions**:
- Use `secrets.GITHUB_TOKEN` (no PAT required for basic releases)
- Set timezone to America/Chicago (or UTC if preferred)
- Default to patch bump when no labels present
- Standard label conventions matching common PR workflows

**Implementation steps**:
1. Create `.github/workflows/release.yml`
2. Create initial `CHANGELOG.md`
3. Test by merging a labeled PR

### CHANGELOG

**Overview**: Initial changelog file that will be auto-updated by the release action.

```markdown
# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial release of WorkOS AuthKit Laravel integration
- User authentication via WorkOS AuthKit
- Organization multi-tenancy support
- Role and permission checking
- Audit logging integration
- Webhook handling for user/org sync
- Session management with auto-refresh
- Blade directives for role/permission checks
- Testing utilities (WorkOS::actingAs)
```

### .gitattributes

**Pattern to follow**: Standard Laravel package conventions

**Overview**: Exclude non-essential files from Composer package distribution.

```gitattributes
# Exclude from distribution
/.github export-ignore
/tests export-ignore
/workbench export-ignore
/.gitattributes export-ignore
/.gitignore export-ignore
/CHANGELOG.md export-ignore
/phpstan.neon export-ignore
/phpunit.xml export-ignore
/pint.json export-ignore
/.editorconfig export-ignore
/docs export-ignore
```

**Key decisions**:
- Exclude all CI/CD and development files
- Exclude workbench (example app) from distribution
- Keep config and src directories

**Implementation steps**:
1. Create or update `.gitattributes`
2. Verify with `git archive --list` (shows what would be in dist)

## Testing Requirements

### Manual Testing

- [ ] Push a test branch and verify CI runs
- [ ] Open a PR and verify all matrix jobs execute
- [ ] Merge with `patch` label and verify release is created
- [ ] Check CHANGELOG.md is updated with new version
- [ ] Verify tag is created on GitHub

## Error Handling

| Error Scenario | Handling Strategy |
|----------------|-------------------|
| CI job fails | Job marked failed, PR blocked if branch protection enabled |
| Release token lacks permissions | Error message in workflow run, needs contents:write |
| No labels on PR | Default to patch bump per configuration |
| skip-release label | No release created, workflow exits cleanly |

## Validation Commands

```bash
# Verify CI would pass locally
composer test
composer analyse
composer format:test

# Verify gitattributes
git archive HEAD --prefix=test/ -o test.tar.gz
tar -tzf test.tar.gz | head -20
rm test.tar.gz
```

## Rollout Considerations

- **Feature flag**: None needed
- **Monitoring**: Watch GitHub Actions workflow runs
- **Alerting**: GitHub sends email on workflow failures
- **Rollback plan**: Delete workflow files and tags if needed

---

*This spec is ready for implementation. Follow the patterns and validate at each step.*
