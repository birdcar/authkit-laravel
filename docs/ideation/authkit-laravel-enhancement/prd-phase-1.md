# PRD Phase 1: CI/CD Foundation

## Overview

Establish automated CI/CD pipelines for the AuthKit Laravel package. This phase creates the foundation for quality assurance and release automation.

## Rationale

CI/CD must be established first because:
1. All subsequent phases benefit from automated testing
2. Release automation ensures consistent versioning
3. CI badge provides immediate credibility signal

## User Stories

### US-1.1: Developer Submits PR
**As a** contributor,
**I want** automated tests to run on my PR,
**So that** I know my changes don't break existing functionality.

**Acceptance Criteria:**
- Tests run automatically on PR open/update
- PHPStan level 8 analysis runs
- Laravel Pint format check runs
- Results visible in PR checks

### US-1.2: Maintainer Releases Version
**As a** maintainer,
**I want** releases created automatically when I merge labeled PRs,
**So that** I don't have to manually create releases and tags.

**Acceptance Criteria:**
- PR with `major` label creates x.0.0 release
- PR with `minor` label creates 0.x.0 release
- PR with `patch` label creates 0.0.x release (default)
- PR with `skip-release` label creates no release
- CHANGELOG.md updated automatically

## Functional Requirements

### FR-1.1: CI Workflow
- **FR-1.1.1**: Trigger on pull_request to main branch
- **FR-1.1.2**: Trigger on push to main branch
- **FR-1.1.3**: Test matrix: PHP 8.2, 8.3, 8.4 × Laravel 10, 11, 12
- **FR-1.1.4**: Run `composer test` for Pest tests
- **FR-1.1.5**: Run `composer analyse` for PHPStan
- **FR-1.1.6**: Run `composer format:test` for Pint
- **FR-1.1.7**: Cache Composer dependencies

### FR-1.2: Release Workflow
- **FR-1.2.1**: Trigger on push to main branch
- **FR-1.2.2**: Use `birdcar/actions/auto-release` action
- **FR-1.2.3**: Configure label mappings (major, minor, patch)
- **FR-1.2.4**: Update CHANGELOG.md on release
- **FR-1.2.5**: Create GitHub release with tag

### FR-1.3: Supporting Files
- **FR-1.3.1**: Create CHANGELOG.md with initial entry
- **FR-1.3.2**: Add `.gitattributes` to exclude dev files from dist

## Non-Functional Requirements

- **NFR-1.1**: CI workflow completes in < 5 minutes
- **NFR-1.2**: Workflow files follow GitHub Actions best practices
- **NFR-1.3**: Use latest stable action versions

## Dependencies

### Prerequisites
- None (this is the first phase)

### Outputs
- `.github/workflows/ci.yml` - CI workflow
- `.github/workflows/release.yml` - Release workflow
- `CHANGELOG.md` - Changelog file
- `.gitattributes` - Distribution exclusions

## Acceptance Criteria

- [ ] CI workflow runs on PR creation
- [ ] CI tests pass for all PHP/Laravel combinations
- [ ] Release workflow creates tag on merge
- [ ] CHANGELOG.md updated automatically
- [ ] CI badge can be added to README
