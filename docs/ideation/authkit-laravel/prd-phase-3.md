# PRD: AuthKit Laravel - Phase 3

**Contract**: ./contract.md
**Phase**: 3 of 6
**Focus**: Team management - organization context, team switching, roles per team, and invitations

## Phase Overview

Phase 3 adds full team/organization management capabilities, matching the patterns established by Laravel Jetstream. While Phase 2 provided basic organization context (current org ID), this phase enables the complete multi-tenant experience: users belonging to multiple teams, switching between them, having different roles per team, and invitation workflows.

This phase is sequenced after authorization because team permissions are an extension of the permission system. The team-scoped role checking builds on `HasWorkOSPermissions`, and the invitation system needs the auth routes to be functional.

After Phase 3 completes, developers can build full multi-tenant SaaS applications. Users can belong to multiple organizations, switch between them, have different permissions in each, and invite new members. This is the complete "Jetstream-like" experience for WorkOS.

## User Stories

1. As a user, I want to belong to multiple organizations so that I can work with different teams
2. As a user, I want to switch between my organizations so that I can access different team contexts
3. As a team admin, I want to invite new members so that I can grow my organization
4. As a team admin, I want to assign roles to members so that I can control their access level
5. As a developer, I want team-scoped permission checks so that users have different abilities per organization

## Functional Requirements

### Model Trait - HasOrganization

- **FR-3.1**: Trait must provide `organizations(): BelongsToMany` relationship
- **FR-3.2**: Trait must provide `ownedOrganizations(): HasMany` relationship (if user can own orgs)
- **FR-3.3**: Trait must provide `currentOrganization(): ?Organization` accessor
- **FR-3.4**: Trait must provide `switchOrganization(string $orgId): bool` method
- **FR-3.5**: Trait must store current organization ID in session
- **FR-3.6**: Trait must provide `belongsToOrganization(string $orgId): bool` check
- **FR-3.7**: Trait must provide `organizationRole(string $orgId): ?string` method
- **FR-3.8**: Trait must provide `hasOrganizationRole(string $orgId, string $role): bool`
- **FR-3.9**: Trait must provide `hasOrganizationPermission(string $orgId, string $permission): bool`

### Organization Model

- **FR-3.10**: Package must provide base Organization model or trait
- **FR-3.11**: Organization must have `workos_id` column
- **FR-3.12**: Organization must have relationship to members (users)
- **FR-3.13**: Organization must provide `findByWorkOSId()` static method
- **FR-3.14**: Organization must provide `syncFromWorkOS()` method to update from API

### Middleware - CheckOrganization

- **FR-3.15**: Middleware must verify user belongs to current organization context
- **FR-3.16**: Middleware must verify user has required role in organization: `workos.org:admin`
- **FR-3.17**: Middleware must be composable with other middleware
- **FR-3.18**: Middleware must support organization ID from route parameter or session

### Team Switching

- **FR-3.19**: Provide route for switching organizations: `POST /organizations/switch`
- **FR-3.20**: Switching must update session with new organization context
- **FR-3.21**: Switching must dispatch `OrganizationSwitched` event
- **FR-3.22**: Switching must validate user membership before allowing
- **FR-3.23**: Provide `workos('org')` shortcut to get current organization ID

### Invitations

- **FR-3.24**: Provide route for sending invitations: `POST /organizations/{org}/invitations`
- **FR-3.25**: Invitations must be sent via WorkOS User Management API
- **FR-3.26**: Provide route for listing pending invitations
- **FR-3.27**: Provide route for revoking invitations
- **FR-3.28**: Dispatch `InvitationSent`, `InvitationRevoked` events

### Sync Command

- **FR-3.29**: `workos:sync-users` command must sync users from WorkOS
- **FR-3.30**: Command must sync organization memberships
- **FR-3.31**: Command must support `--organization=` filter
- **FR-3.32**: Command must be runnable via scheduler for continuous sync
- **FR-3.33**: Command must handle pagination for large user sets

### Migrations

- **FR-3.34**: Migration must create `organizations` table with `workos_id`
- **FR-3.35**: Migration must create `organization_user` pivot table
- **FR-3.36**: Pivot must include `role` column for organization-scoped roles

## Non-Functional Requirements

- **NFR-3.1**: Organization switching must complete within 100ms (no API calls)
- **NFR-3.2**: Sync command must process at least 100 users per second
- **NFR-3.3**: All organization checks must use cached/session data, not API calls
- **NFR-3.4**: Invitation emails must be handled by WorkOS (not package)

## Dependencies

### Prerequisites

- Phase 1 complete (service provider, session manager)
- Phase 2 complete (HasWorkOSPermissions trait, auth routes)

### Outputs for Next Phase

- HasOrganization trait for multi-tenant user models
- Organization model/trait
- Team switching functionality
- Invitation system
- Sync command for user/org data
- OrganizationSwitched and invitation events

## Acceptance Criteria

- [ ] User with HasOrganization trait can call `$user->organizations`
- [ ] `$user->switchOrganization($orgId)` updates session context
- [ ] `$user->hasOrganizationRole($orgId, 'admin')` checks org-specific role
- [ ] `middleware('workos.org:admin')` blocks non-admins in current org
- [ ] `/organizations/switch` route changes active organization
- [ ] OrganizationSwitched event fires on switch
- [ ] Invitations can be sent via API endpoint
- [ ] `workos:sync-users` imports users from WorkOS
- [ ] Sync command handles pagination correctly
- [ ] Organization migrations create correct schema
- [ ] All unit and feature tests passing

---

*Review this PRD and provide feedback before spec generation.*
