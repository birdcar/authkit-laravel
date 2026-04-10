# Contract: AuthKit Laravel Package Enhancement

## Problem Statement

The AuthKit Laravel package (`workos/authkit-laravel`) is feature-complete but lacks:
1. **CI/CD automation** - No GitHub Actions for testing PRs or automating releases
2. **Example application** - No reference implementation showing how to use all package features
3. **Documentation** - No comprehensive README to help developers get started

Without these, potential users cannot:
- Trust the package quality (no visible CI status)
- See how features work together in a real application
- Quickly understand installation and configuration

## Goals

1. **Automated CI/CD Pipeline**
   - Run tests, PHPStan (level 8), and Laravel Pint on all PRs
   - Automate releases using `birdcar/actions/auto-release` (PR label-driven)
   - Support Laravel 10, 11, and 12 across PHP 8.2, 8.3, 8.4

2. **Complete Example Application (Todo App)**
   - Full Laravel 12 application in `workbench/` directory
   - Livewire-based UI using Tailwind + Flux Pro
   - SQLite database for zero-config local development
   - Demonstrates all AuthKit features:
     - User authentication via WorkOS AuthKit
     - Multi-tenant organizations
     - Role-based access control
     - Audit logging
     - Session management
     - All Admin Portal intents (SSO, Directory Sync, Audit Logs, Log Streams, Domain Verification)
   - Basic test suite showing how to test with the package

3. **Composer Commands**
   - `composer serve` - Run the example app
   - `composer fresh` - Reset and seed the example database

4. **Comprehensive Documentation**
   - README in `.github/README.md` (GitHub-specific location)
   - Installation and configuration guide
   - Feature documentation with code examples
   - Contributing guidelines
   - Remove any other README files

## Success Criteria

1. **CI/CD**
   - [ ] PRs trigger CI workflow (tests, PHPStan, Pint)
   - [ ] CI badge visible in README
   - [ ] Merging labeled PRs creates GitHub releases automatically
   - [ ] CHANGELOG.md auto-updated on release

2. **Example Application**
   - [ ] `composer serve` launches working Todo app at localhost:8000
   - [ ] Can sign up/login via WorkOS AuthKit
   - [ ] Can create, complete, and delete todos
   - [ ] Organization switching works with separate todo lists
   - [ ] Admin Portal links functional for each intent
   - [ ] Audit log shows user actions
   - [ ] UI is polished with Flux Pro components
   - [ ] Basic tests pass with `composer test:example`

3. **Documentation**
   - [ ] README explains installation in < 5 minutes
   - [ ] All public API methods documented
   - [ ] Code examples for common use cases
   - [ ] Contributing section explains local development

## Scope

### In Scope
- GitHub Actions CI workflow
- GitHub Actions release workflow using birdcar/actions/auto-release
- Laravel 12 example app in `workbench/`
- Livewire components with Flux Pro UI
- SQLite database with migrations and seeders
- All WorkOS Admin Portal intents
- Comprehensive `.github/README.md`
- Composer scripts for running example app
- Basic feature tests for the example app

### Out of Scope
- Deployment configurations (Heroku, AWS, etc.)
- Production database setup (MySQL, PostgreSQL)
- Email/notification features
- User profile editing (beyond what AuthKit provides)
- Mobile responsiveness (desktop-first demo)
- Internationalization
- Real-time features (websockets)

## Decisions Made

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Example location | `workbench/` | Laravel package convention, auto-excluded |
| UI framework | Tailwind + Flux Pro | Livewire's official library, user has license |
| Database | SQLite | Zero config, portable, perfect for demos |
| Tests | Basic feature tests | Demonstrates testing with package |
| Release automation | birdcar/actions/auto-release | Label-driven, maintains CHANGELOG |
