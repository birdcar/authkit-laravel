# PRD: AuthKit Laravel - Phase 5

**Contract**: ./contract.md
**Phase**: 5 of 6
**Focus**: Webhooks and events - webhook handling, signature verification, Laravel event dispatching, and Events API example

## Phase Overview

Phase 5 implements the webhook and event infrastructure for real-time WorkOS data synchronization. This includes a webhook controller that verifies signatures and dispatches Laravel events, handlers for user and organization webhook events, and an example Events API consumer for real-time event streaming.

This phase comes after the core features because webhooks are about keeping data in sync, which requires having the data models and auth system in place first. The Events API example is provided as a sidecar/separate process since it's a long-running connection.

After Phase 5 completes, developers can keep their local data synchronized with WorkOS in real-time. User updates, organization changes, and membership modifications automatically flow into the Laravel application. The Events API example shows how to set up continuous event streaming for more demanding use cases.

## User Stories

1. As a developer, I want webhooks to automatically sync user data so that my app stays current with WorkOS
2. As a developer, I want webhook signatures verified so that I know events are legitimate
3. As a developer, I want Laravel events dispatched so that I can hook into the sync process
4. As a developer, I want to handle organization membership changes so that permissions update automatically
5. As a developer, I want an Events API example so that I can implement real-time streaming if needed

## Functional Requirements

### Webhook Controller

- **FR-5.1**: Controller must handle POST requests to configurable webhook endpoint
- **FR-5.2**: Controller must verify webhook signatures using WorkOS SDK
- **FR-5.3**: Controller must return 400 if signature verification fails
- **FR-5.4**: Controller must return 200 immediately after queuing event processing
- **FR-5.5**: Controller must parse event type and payload from request body
- **FR-5.6**: Controller must dispatch appropriate Laravel event based on webhook type

### Signature Verification

- **FR-5.7**: Use `config('workos.webhook_secret')` for verification
- **FR-5.8**: Verify using WorkOS SDK's `Webhook::constructEvent()` method
- **FR-5.9**: Log verification failures with request details (not secret)
- **FR-5.10**: Support webhook secret rotation (try current, fall back to previous)

### Webhook Events - User

- **FR-5.11**: Handle `user.created` - dispatch `WorkOSUserCreated` event
- **FR-5.12**: Handle `user.updated` - dispatch `WorkOSUserUpdated` event
- **FR-5.13**: Handle `user.deleted` - dispatch `WorkOSUserDeleted` event
- **FR-5.14**: Events must include parsed user data from webhook payload
- **FR-5.15**: Provide default listeners that sync user data to local database

### Webhook Events - Organization

- **FR-5.16**: Handle `organization.created` - dispatch `WorkOSOrganizationCreated` event
- **FR-5.17**: Handle `organization.updated` - dispatch `WorkOSOrganizationUpdated` event
- **FR-5.18**: Handle `organization.deleted` - dispatch `WorkOSOrganizationDeleted` event
- **FR-5.19**: Events must include parsed organization data

### Webhook Events - Membership

- **FR-5.20**: Handle `organization_membership.created` - dispatch `WorkOSMembershipCreated`
- **FR-5.21**: Handle `organization_membership.updated` - dispatch `WorkOSMembershipUpdated`
- **FR-5.22**: Handle `organization_membership.deleted` - dispatch `WorkOSMembershipDeleted`
- **FR-5.23**: Membership events must include user_id, organization_id, and role

### Webhook Events - Authentication

- **FR-5.24**: Handle `authentication.email_verification_succeeded` event
- **FR-5.25**: Handle `authentication.magic_auth_succeeded` event
- **FR-5.26**: Handle `authentication.mfa_succeeded` event
- **FR-5.27**: Handle `authentication.oauth_succeeded` event
- **FR-5.28**: Handle `authentication.password_reset_succeeded` event
- **FR-5.29**: Handle `session.created`, `session.revoked` events

### Generic Event Handling

- **FR-5.30**: Dispatch `WebhookReceived` event for ALL webhook types (before specific handling)
- **FR-5.31**: Allow users to disable default listeners via config
- **FR-5.32**: Support custom event handlers via event discovery

### Events API Example

- **FR-5.33**: Provide example artisan command `workos:events-listen` for Events API consumption
- **FR-5.34**: Example must demonstrate SSE connection to WorkOS Events API
- **FR-5.35**: Example must show event parsing and Laravel event dispatching
- **FR-5.36**: Example must handle connection failures and reconnection
- **FR-5.37**: Document that Events API is recommended over webhooks for directory sync

### Routes Configuration

- **FR-5.38**: Webhook route must be configurable: `config('workos.webhooks.prefix')`
- **FR-5.39**: Webhook route must be disableable: `config('workos.webhooks.enabled')`
- **FR-5.40**: Webhook route must NOT require CSRF verification
- **FR-5.41**: Webhook route must NOT require authentication

## Non-Functional Requirements

- **NFR-5.1**: Webhook endpoint must respond within 100ms (queue processing for later)
- **NFR-5.2**: Signature verification must complete within 10ms
- **NFR-5.3**: Failed webhook processing must be retried via queue
- **NFR-5.4**: Events API example must handle 1000+ events per minute
- **NFR-5.5**: Webhook secret must never be logged or exposed in errors

## Dependencies

### Prerequisites

- Phase 1 complete (service provider, WorkOS SDK)
- Phase 2 complete (User model with traits, events infrastructure)
- Phase 3 complete (Organization model for org webhooks)

### Outputs for Next Phase

- Working webhook endpoint with signature verification
- Laravel events for all WorkOS webhook types
- Default listeners for user/org sync
- Events API example command
- WebhookReceived generic event

## Acceptance Criteria

- [ ] POST to `/webhooks/workos` with valid signature returns 200
- [ ] POST with invalid signature returns 400
- [ ] `user.created` webhook dispatches `WorkOSUserCreated` event
- [ ] Default listener creates/updates local user on `user.updated`
- [ ] `organization_membership.created` updates pivot table
- [ ] `WebhookReceived` event fires for every webhook
- [ ] Custom listeners can be registered and receive events
- [ ] `workos:events-listen` command connects to Events API
- [ ] Events API example handles reconnection on failure
- [ ] Webhook processing is queued, not synchronous
- [ ] All unit and feature tests passing

---

*Review this PRD and provide feedback before spec generation.*
