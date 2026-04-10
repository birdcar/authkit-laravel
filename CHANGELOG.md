# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.0](https://github.com/birdcar/authkit-laravel/compare/v0.2.0...v0.3.0) (2026-04-10)


### Features

* Add Laravel 13 support (`illuminate/contracts ^13.0`, `illuminate/support ^13.0`, `orchestra/testbench ^11.0`)


## [0.2.0](https://github.com/birdcar/authkit-laravel/compare/v0.1.0...v0.2.0) (2026-04-08)


### ⚠ BREAKING CHANGES

* HasOrganization::switchOrganization() has been removed. Use the /organizations/switch endpoint or redirect to WorkOS login with organization_id parameter.
* SessionManagerInterface and the Laravel session driver have been removed. Config keys session.cookie_session and session.refresh_buffer_minutes no longer exist.
* Removed deprecated trait aliases. Users must update imports from WorkOS\AuthKit\Traits\* to WorkOS\AuthKit\Models\Concerns\* namespace.
* PHP 8.2 and Laravel 10 are no longer supported. Upgrade to PHP 8.3+ and Laravel 11+ before updating this package.
* The pivot table is renamed from `organization_user` to `organization_memberships` for clarity. The bundled Organization model is removed - users must provide their own via config or use the install wizard to generate one.

### Features

* **02-02:** add WorkOSFakeExampleTest.php with three fake patterns ([75b1636](https://github.com/birdcar/authkit-laravel/commit/75b163656051c8120fae4c52d7148bb3b4df06f4))
* **02-02:** convert AuthTest dashboard test to WorkOS::actingAs() pattern ([9e3e2b5](https://github.com/birdcar/authkit-laravel/commit/9e3e2b5c84ceca4471bac9f17a663cceac8ff65f))
* **03-01:** add NodeToolingDetector with WorkOS CLI delegation ([ccd81c5](https://github.com/birdcar/authkit-laravel/commit/ccd81c5d578f69e979ac5b7519119494e34d1d88))
* **03-02:** add --force bypass to WizardFlow, LaravelWorkosMigrator, AuthSystemInstaller ([add644e](https://github.com/birdcar/authkit-laravel/commit/add644ed82b660cef53f044c0f14d9e7e6d86a86))
* **03-02:** harden --mini to write env placeholders and migration plan file ([77d63c4](https://github.com/birdcar/authkit-laravel/commit/77d63c4318e68f53b912f607725c920e4576a610))
* **03-03:** add per-key duplicate guard to EnvManager.applyChanges ([427c20e](https://github.com/birdcar/authkit-laravel/commit/427c20eaa21377e93fc0de60138e1ace7dcc4379))
* **03-03:** harden AuthSystemInstaller post-write verification ([cf514a2](https://github.com/birdcar/authkit-laravel/commit/cf514a2b2ca1e770422596133bab3f75539f901b))
* **04-02:** add RBAC middleware to routes and remove ExampleTest stub ([a60c0f1](https://github.com/birdcar/authkit-laravel/commit/a60c0f13de40584fbfc2a980f465da1f63f427e4))
* **04-02:** convert feature tests to WorkOS::fake() and add RBAC tests ([b040176](https://github.com/birdcar/authkit-laravel/commit/b04017661b3ce3a7cacfe65b7c82c28ce8c3ba77))
* add AdminPortal Livewire widget components (Phase 4) ([c0f848b](https://github.com/birdcar/authkit-laravel/commit/c0f848bcce5b5c05d8f42c0c16b246fad87e6fcf))
* add API Keys, Data Integrations, Directory Sync, and Settings widgets (Phase 5) ([c9676bf](https://github.com/birdcar/authkit-laravel/commit/c9676bf1e09c00360b59eda329c0ee16113ad350))
* add release-please, Codecov, Dependabot, and Packagist-ready install ([6959c17](https://github.com/birdcar/authkit-laravel/commit/6959c1739e4e2b49e59cd7f660fdde2883622050))
* add UserManagement Livewire widget components ([e6a687c](https://github.com/birdcar/authkit-laravel/commit/e6a687c0c00aa34a09dcb0d80ae87b6718b3d7df))
* add UserProfile Livewire widget components (Phase 3) ([0905c00](https://github.com/birdcar/authkit-laravel/commit/0905c001fd312a025310fdbb18bd26509b2f6502))
* Drop Laravel 10 and PHP 8.2 support ([3876fae](https://github.com/birdcar/authkit-laravel/commit/3876fae3bc21fb32b60e49fc9a52a7b8b46bb99d))
* **install:** Add environment detection for smart install ([5ccd57e](https://github.com/birdcar/authkit-laravel/commit/5ccd57e262f9db7bd323ca3a2625dd650f2e1315))
* **install:** Add interactive wizard with component installers ([1471a1a](https://github.com/birdcar/authkit-laravel/commit/1471a1a421ef2e6e1ca3ebe69b818f8bb73bd967))
* **install:** Add migration plan generator for existing auth ([6cd46d9](https://github.com/birdcar/authkit-laravel/commit/6cd46d99c7870a88a697483220aec046970be31e))
* **install:** Enhance wizard with model generation and migration ([76cd01a](https://github.com/birdcar/authkit-laravel/commit/76cd01a0c5b4d6c2745a98728e9d7cf70b315905))
* **widgets:** add Livewire widget infrastructure — traits, CSS, service provider ([14f131f](https://github.com/birdcar/authkit-laravel/commit/14f131fc8087451b49c8aca611fcd6d47aac44e0))


### Bug Fixes

* **02-01:** rename tearDownWorkOS to tearDownInteractsWithWorkOS ([c4e309b](https://github.com/birdcar/authkit-laravel/commit/c4e309ba886d50a59202c185062d02444dcef169))
* **03:** revise plans based on checker feedback ([3d8c201](https://github.com/birdcar/authkit-laravel/commit/3d8c2019f751346cee4048ef9338ca80c1dc9805))
* Add Laravel 10 compatibility for migration publishing ([9b9ef30](https://github.com/birdcar/authkit-laravel/commit/9b9ef30dcd0b9e313283d02bb7d17e2b13980663))
* **docs:** correct InteractsWithWorkOS teardown advice and audit example ([c9738b1](https://github.com/birdcar/authkit-laravel/commit/c9738b18456baa6e8002a271261493fba8fc6c03))
* Improve code quality and validation ([e8689a7](https://github.com/birdcar/authkit-laravel/commit/e8689a7cc550f866ee81d745486566e149bb1127))
* Resolve PHPStan level 8 errors ([30a39ba](https://github.com/birdcar/authkit-laravel/commit/30a39ba32d6436ed52bb66339890f9576ec42864))
* **testing:** add getLogoutUrl and destroySession to WorkOSFake ([fd3eba4](https://github.com/birdcar/authkit-laravel/commit/fd3eba4e7c1f6bfcf63f2b793ca81573257f8aa4))
* **tests:** register workos guard in test environment and use it in AuditLogger ([3215a85](https://github.com/birdcar/authkit-laravel/commit/3215a85bdf61610923f1c64e4303f0b666bdc475))
* **tests:** Use uses() instead of pest() for group configuration ([1290893](https://github.com/birdcar/authkit-laravel/commit/12908932b4b2cca735ec4604b6fe983c466cd7c5))
* Use explicit SQLite database for tests ([a3676af](https://github.com/birdcar/authkit-laravel/commit/a3676af5f758e38eb8906a490bd2595a170ecf6e))


### Miscellaneous Chores

* **04-01:** satisfy WORK-06 and WORK-07 compliance gaps ([e7d10df](https://github.com/birdcar/authkit-laravel/commit/e7d10df4557f3ffb48b3421c4b5c5067eb9c4d79))
* add project config ([4703d45](https://github.com/birdcar/authkit-laravel/commit/4703d4559ac16d77e3c4e7e8716d19f5d08aef5c))
* merge executor worktree (02-01 teardown fix + DI test) ([8a7321a](https://github.com/birdcar/authkit-laravel/commit/8a7321a2888e87c440fbef4a5bc260ea60e57eb1))
* merge executor worktree (02-02 workbench example tests) ([aec17d2](https://github.com/birdcar/authkit-laravel/commit/aec17d298cc4e746e4febe861284697eb580cad0))
* merge executor worktree (worktree-agent-afba48ba) ([9fc498d](https://github.com/birdcar/authkit-laravel/commit/9fc498d7c52ff345f14a2d80e496b1ac0304c61e))
* Remove PruneSessionsCommand ([e7569ca](https://github.com/birdcar/authkit-laravel/commit/e7569ca764abfa43698255cb3f35068c350aa4d9))
* Rename package to birdcar/authkit-laravel ([9211719](https://github.com/birdcar/authkit-laravel/commit/92117191b61141569b4010f1a8013595e0dbdd36))
* update planning config ([240939e](https://github.com/birdcar/authkit-laravel/commit/240939e31adf231833bafb130611b7d5912df3a4))


### Code Refactoring

* Consolidate to WorkOS cookie session ([4f1cd6e](https://github.com/birdcar/authkit-laravel/commit/4f1cd6ea8ee313689cc124efb4af45d2205eccc5))
* Modernize codebase with PHP 8.3 features ([5673977](https://github.com/birdcar/authkit-laravel/commit/567397720ce3bbe70aebcc9b8d5f4baa4d7eab5e))
* Redirect org switch through WorkOS login ([d4884dc](https://github.com/birdcar/authkit-laravel/commit/d4884dcbad873ff20afd2a65fbaeb030d9fea314))
* Remove package Organization model, rename pivot table ([0815b87](https://github.com/birdcar/authkit-laravel/commit/0815b87eeae9bdb0dda9a03887d04e0011389cf5))

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
