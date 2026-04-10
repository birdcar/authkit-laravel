# PRD Phase 2: Example App Foundation

## Overview

Scaffold a complete Laravel 12 application in `workbench/` with Livewire and Flux Pro. This phase establishes the technical foundation for the example Todo app.

## Rationale

The app foundation must be established before features because:
1. Livewire + Flux Pro setup is complex and best done once
2. Database migrations need to exist before auth/feature work
3. Composer scripts enable iterative development

## User Stories

### US-2.1: Contributor Runs Example
**As a** package contributor,
**I want** to run the example app with a single command,
**So that** I can manually verify my changes work.

**Acceptance Criteria:**
- `composer serve` starts the app at localhost:8000
- App loads without errors
- No external services required (SQLite)

### US-2.2: Contributor Resets Database
**As a** contributor,
**I want** to reset the database to a clean state,
**So that** I can test from scratch.

**Acceptance Criteria:**
- `composer fresh` migrates and seeds database
- All tables recreated
- Demo data populated

## Functional Requirements

### FR-2.1: Laravel Application
- **FR-2.1.1**: Laravel 12 application in `workbench/` directory
- **FR-2.1.2**: Configure SQLite database (database/database.sqlite)
- **FR-2.1.3**: Use path repository to require `workos/authkit-laravel:@dev`
- **FR-2.1.4**: Run `workos:install` during setup

### FR-2.2: Frontend Stack
- **FR-2.2.1**: Install Livewire 3.x
- **FR-2.2.2**: Install Flux Pro via Composer
- **FR-2.2.3**: Configure Tailwind CSS with Flux preset
- **FR-2.2.4**: Set up Vite for asset compilation

### FR-2.3: Database Schema
- **FR-2.3.1**: Users table with workos_id column
- **FR-2.3.2**: Organizations table (synced from WorkOS)
- **FR-2.3.3**: Organization_user pivot table
- **FR-2.3.4**: Todos table (user_id, organization_id, title, completed)

### FR-2.4: Composer Scripts
- **FR-2.4.1**: `serve` - Run example app server
- **FR-2.4.2**: `fresh` - Migrate and seed example database
- **FR-2.4.3**: `test:example` - Run example app tests (added Phase 5)

### FR-2.5: Base Layout
- **FR-2.5.1**: App layout with Flux navigation
- **FR-2.5.2**: Guest layout for login/signup
- **FR-2.5.3**: Flash message component
- **FR-2.5.4**: Loading states

## Non-Functional Requirements

- **NFR-2.1**: App starts in < 3 seconds
- **NFR-2.2**: No external dependencies required (works offline except WorkOS auth)
- **NFR-2.3**: `workbench/` excluded from package distribution

## Dependencies

### Prerequisites
- Phase 1 (CI/CD) - for .gitattributes exclusion

### Outputs
- `workbench/` - Complete Laravel application
- Composer scripts in root `composer.json`
- Updated `.gitattributes` to exclude workbench from dist

## File Structure

```
workbench/
├── app/
│   ├── Http/
│   │   └── Controllers/
│   ├── Livewire/
│   │   └── (components added in later phases)
│   └── Models/
│       ├── User.php
│       ├── Organization.php
│       └── Todo.php
├── database/
│   ├── migrations/
│   └── seeders/
├── resources/
│   ├── views/
│   │   ├── components/
│   │   │   └── layouts/
│   │   │       ├── app.blade.php
│   │   │       └── guest.blade.php
│   │   └── livewire/
│   ├── css/
│   │   └── app.css
│   └── js/
│       └── app.js
├── routes/
│   └── web.php
├── .env.example
├── composer.json
├── package.json
├── tailwind.config.js
└── vite.config.js
```

## Acceptance Criteria

- [ ] `composer serve` starts app at localhost:8000
- [ ] App loads Flux Pro components correctly
- [ ] SQLite database created automatically
- [ ] `composer fresh` resets database
- [ ] Tailwind CSS compiles without errors
- [ ] workbench excluded from packagist distribution
