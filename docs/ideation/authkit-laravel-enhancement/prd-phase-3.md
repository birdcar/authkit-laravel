# PRD Phase 3: Authentication & Organizations

## Overview

Implement WorkOS AuthKit authentication and organization multi-tenancy in the example app. Users authenticate via WorkOS and can belong to multiple organizations.

## Rationale

Authentication and organizations must be implemented before Todo features because:
1. Todos are scoped to users and organizations
2. Admin Portal requires authenticated organization context
3. Audit logging requires user/organization context

## User Stories

### US-3.1: User Signs Up
**As a** new user,
**I want** to sign up via WorkOS AuthKit,
**So that** I can create an account securely.

**Acceptance Criteria:**
- Clicking "Sign Up" redirects to WorkOS AuthKit
- After signup, user is created in local database
- User redirected to dashboard

### US-3.2: User Logs In
**As a** returning user,
**I want** to log in via WorkOS AuthKit,
**So that** I can access my todos.

**Acceptance Criteria:**
- Clicking "Log In" redirects to WorkOS AuthKit
- After login, session is established
- User redirected to dashboard (or return_to URL)

### US-3.3: User Logs Out
**As a** logged-in user,
**I want** to log out,
**So that** I can secure my session.

**Acceptance Criteria:**
- Clicking "Log Out" ends session
- User redirected to login page
- Session cleared from both Laravel and WorkOS

### US-3.4: User Switches Organization
**As a** user with multiple organizations,
**I want** to switch between organizations,
**So that** I can view different todo lists.

**Acceptance Criteria:**
- Organization switcher in navigation
- Switching updates current organization context
- Todos filter to current organization

### US-3.5: User Views Organization Members
**As an** organization member,
**I want** to see who else is in my organization,
**So that** I know who has access.

**Acceptance Criteria:**
- Members list on organization settings page
- Shows name, email, and role
- Admin can see additional options

## Functional Requirements

### FR-3.1: Authentication
- **FR-3.1.1**: Use package's built-in auth routes (/auth/login, /auth/callback, /auth/logout)
- **FR-3.1.2**: Configure User model with HasWorkOSId trait
- **FR-3.1.3**: Implement findOrCreateByWorkOS method on User model
- **FR-3.1.4**: Display impersonation banner when applicable
- **FR-3.1.5**: Show user profile info in navigation

### FR-3.2: Organizations
- **FR-3.2.1**: Use package's Organization model with HasWorkOSId trait
- **FR-3.2.2**: Sync organizations via webhooks
- **FR-3.2.3**: Organization switcher component in navigation
- **FR-3.2.4**: Store current organization in session
- **FR-3.2.5**: Apply organization scope to Todo queries

### FR-3.3: UI Components
- **FR-3.3.1**: Login page with AuthKit button
- **FR-3.3.2**: Navigation with user dropdown (profile, logout)
- **FR-3.3.3**: Organization switcher dropdown
- **FR-3.3.4**: Impersonation alert banner
- **FR-3.3.5**: Organization settings page (members list)

### FR-3.4: Middleware
- **FR-3.4.1**: Apply workos.auth middleware to protected routes
- **FR-3.4.2**: Apply workos.organization middleware where needed
- **FR-3.4.3**: Apply workos.impersonation middleware for banner

## Non-Functional Requirements

- **NFR-3.1**: Login flow completes in < 2 seconds (excluding WorkOS redirect)
- **NFR-3.2**: Organization switch is instant (no page reload with Livewire)

## Dependencies

### Prerequisites
- Phase 2 (App Foundation) - for User/Organization models

### Outputs
- Livewire components: Login, Navigation, OrganizationSwitcher
- Blade views: login page, dashboard
- Routes: Protected routes with middleware

## Acceptance Criteria

- [ ] Can sign up as new user via WorkOS
- [ ] Can log in as existing user
- [ ] Can log out and session is cleared
- [ ] Organization switcher shows all user's organizations
- [ ] Switching organization updates context
- [ ] Impersonation banner shows when impersonating
- [ ] User dropdown shows profile info
