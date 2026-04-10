# PRD Phase 4: Todo Features & Admin Portal

## Overview

Implement the core Todo CRUD functionality, Admin Portal integration for all intents, and audit logging. This phase builds the main features of the example application.

## Rationale

This phase combines related features:
1. Todos are the primary domain showing auth/org integration
2. Admin Portal demonstrates enterprise features
3. Audit logging tracks all user actions

## User Stories

### US-4.1: User Creates Todo
**As a** user,
**I want** to create new todos,
**So that** I can track my tasks.

**Acceptance Criteria:**
- Input field to enter todo title
- Todo appears in list immediately (Livewire)
- Todo scoped to current user and organization

### US-4.2: User Completes Todo
**As a** user,
**I want** to mark todos as complete,
**So that** I can track progress.

**Acceptance Criteria:**
- Checkbox to toggle completion
- Visual distinction for completed items
- Toggle updates without page reload

### US-4.3: User Deletes Todo
**As a** user,
**I want** to delete todos,
**So that** I can clean up my list.

**Acceptance Criteria:**
- Delete button on each todo
- Confirmation before deletion
- Todo removed immediately

### US-4.4: Org Admin Configures SSO
**As an** organization admin,
**I want** to configure SSO for my org,
**So that** members can sign in via our IdP.

**Acceptance Criteria:**
- "Configure SSO" link in org settings
- Redirects to WorkOS Admin Portal (sso intent)
- Returns to app after configuration

### US-4.5: Org Admin Configures Directory Sync
**As an** organization admin,
**I want** to configure Directory Sync,
**So that** users are synced from our IdP.

**Acceptance Criteria:**
- "Configure Directory Sync" link in org settings
- Redirects to WorkOS Admin Portal (dsync intent)
- Returns to app after configuration

### US-4.6: Org Admin Views Audit Logs
**As an** organization admin,
**I want** to view audit logs,
**So that** I can track security events.

**Acceptance Criteria:**
- "View Audit Logs" link in org settings
- Redirects to WorkOS Admin Portal (audit_logs intent)
- Returns to app after viewing

### US-4.7: Org Admin Configures Log Streams
**As an** organization admin,
**I want** to configure Log Streams,
**So that** logs are exported to our SIEM.

**Acceptance Criteria:**
- "Configure Log Streams" link in org settings
- Redirects to WorkOS Admin Portal (log_streams intent)
- Returns to app after configuration

### US-4.8: Org Admin Verifies Domain
**As an** organization admin,
**I want** to verify my domain,
**So that** users can be auto-enrolled.

**Acceptance Criteria:**
- "Verify Domain" link in org settings
- Redirects to WorkOS Admin Portal (domain_verification intent)
- Returns to app after verification

### US-4.9: Org Admin Renews Certificates
**As an** organization admin,
**I want** to renew SAML certificates,
**So that** SSO continues working.

**Acceptance Criteria:**
- "Manage Certificates" link in org settings
- Redirects to WorkOS Admin Portal (certificate_renewal intent)
- Returns to app after renewal

### US-4.10: Admin Views Audit Trail
**As an** admin,
**I want** to see what actions users have taken,
**So that** I can monitor activity.

**Acceptance Criteria:**
- Audit trail shows in organization settings
- Lists recent actions with timestamps
- Includes user, action type, and target

## Functional Requirements

### FR-4.1: Todo CRUD
- **FR-4.1.1**: Create todo with title
- **FR-4.1.2**: List todos for current user/organization
- **FR-4.1.3**: Toggle todo completion status
- **FR-4.1.4**: Delete todo with confirmation
- **FR-4.1.5**: Filter todos (all, active, completed)

### FR-4.2: Admin Portal Integration
- **FR-4.2.1**: Generate portal link for `sso` intent
- **FR-4.2.2**: Generate portal link for `dsync` intent
- **FR-4.2.3**: Generate portal link for `audit_logs` intent
- **FR-4.2.4**: Generate portal link for `log_streams` intent
- **FR-4.2.5**: Generate portal link for `domain_verification` intent
- **FR-4.2.6**: Generate portal link for `certificate_renewal` intent
- **FR-4.2.7**: Configure return URL to organization settings

### FR-4.3: Audit Logging
- **FR-4.3.1**: Log todo creation events
- **FR-4.3.2**: Log todo completion events
- **FR-4.3.3**: Log todo deletion events
- **FR-4.3.4**: Include user, organization, and target in audit events
- **FR-4.3.5**: Display recent audit events in org settings

### FR-4.4: UI Components
- **FR-4.4.1**: TodoList Livewire component
- **FR-4.4.2**: TodoItem Livewire component
- **FR-4.4.3**: TodoForm Livewire component
- **FR-4.4.4**: OrgSettings page with Admin Portal links
- **FR-4.4.5**: AuditTrail component

## Non-Functional Requirements

- **NFR-4.1**: Todo CRUD operations complete in < 200ms
- **NFR-4.2**: Admin Portal redirect happens in < 1 second
- **NFR-4.3**: Audit events sent asynchronously (non-blocking)

## Dependencies

### Prerequisites
- Phase 3 (Auth & Organizations) - for user/org context

### Outputs
- Livewire components: TodoList, TodoItem, TodoForm, OrgSettings, AuditTrail
- Controller: AdminPortalController
- Routes: /todos, /organizations/{id}/settings, /admin-portal/{intent}

## Acceptance Criteria

- [ ] Can create, complete, and delete todos
- [ ] Todos are scoped to current organization
- [ ] All 6 Admin Portal intents work
- [ ] Admin Portal returns to app correctly
- [ ] Audit events are logged for todo actions
- [ ] Audit trail displays in org settings
