# PRD: AuthKit Laravel - Phase 4

**Contract**: ./contract.md
**Phase**: 4 of 6
**Focus**: Audit logging - WorkOS Audit Logs API integration, middleware, and test assertions

## Phase Overview

Phase 4 integrates WorkOS Audit Logs for compliance and security monitoring. This includes a service for sending audit events, middleware for automatic route-level auditing, and test utilities for verifying audit behavior in tests.

This phase can technically run in parallel with Phase 3 since audit logging is somewhat independent, but it's sequenced here because having team context makes audit logs more useful (you can log which organization an action occurred in). The audit system needs the authentication from Phase 1-2 to know who performed actions.

After Phase 4 completes, developers can instrument their applications for compliance requirements. Actions are automatically logged, test suites can verify logging behavior, and security teams have visibility into user activity via the WorkOS dashboard.

## User Stories

1. As a compliance officer, I want all sensitive actions logged so that we meet audit requirements
2. As a developer, I want automatic route-level auditing so that I don't have to manually log every action
3. As a developer, I want to manually log custom events so that I can track business-specific actions
4. As a developer, I want to verify audit logs in tests so that I can ensure logging works correctly
5. As a security analyst, I want audit logs to include context (user, org, IP) so that I can investigate incidents

## Functional Requirements

### AuditLogger Service

- **FR-4.1**: Service must wrap WorkOS Audit Logs API
- **FR-4.2**: Service must be injectable via constructor or facade
- **FR-4.3**: `log(string $action, array $targets, array $metadata)` must send event to WorkOS
- **FR-4.4**: Service must automatically include actor (current user) if authenticated
- **FR-4.5**: Service must automatically include context (IP, user agent, timestamp)
- **FR-4.6**: Service must include organization ID if in organization context
- **FR-4.7**: Service must be no-op if `config('workos.features.audit_logs')` is false
- **FR-4.8**: Service must queue events for async sending if configured
- **FR-4.9**: Service must handle API failures gracefully (log error, don't crash)

### Audit Event Structure

- **FR-4.10**: Events must follow WorkOS Audit Log schema
- **FR-4.11**: `action` must include type and human-readable name
- **FR-4.12**: `actor` must include type, id, name, and metadata
- **FR-4.13**: `targets` must be array of {type, id, name?, metadata?}
- **FR-4.14**: `context` must include location (IP) and user_agent
- **FR-4.15**: `metadata` must support arbitrary key-value pairs
- **FR-4.16**: `occurred_at` must be ISO 8601 timestamp

### AuditMiddleware

- **FR-4.17**: Middleware must log successful requests (2xx responses)
- **FR-4.18**: Middleware must accept action name as parameter: `workos.audit:user.viewed`
- **FR-4.19**: Middleware must infer action from HTTP method if not specified (GET→read, POST→create, etc.)
- **FR-4.20**: Middleware must extract targets from route parameters
- **FR-4.21**: Middleware must be registered as `workos.audit` alias
- **FR-4.22**: Middleware must not log failed requests (4xx, 5xx) unless configured

### Auditable Contract

- **FR-4.23**: Provide `Auditable` interface for models
- **FR-4.24**: Interface must define `toAuditTarget(): array` method
- **FR-4.25**: Models implementing Auditable can be passed directly to `log()` targets
- **FR-4.26**: Provide `HasAuditTrail` trait for common implementation

### Facade/Helper Integration

- **FR-4.27**: `WorkOS::audit($action, $targets, $metadata)` shortcut method
- **FR-4.28**: `workos()->audit($action, $targets, $metadata)` must work
- **FR-4.29**: Audit methods must be chainable or return void

### Test Assertions

- **FR-4.30**: `WorkOS::fake()` must capture audit events instead of sending
- **FR-4.31**: `assertAudited(string $action)` must verify event was logged
- **FR-4.32**: `assertAudited($action, callable $callback)` must verify event matches criteria
- **FR-4.33**: `assertNotAudited(string $action)` must verify event was NOT logged
- **FR-4.34**: `assertAuditedCount(int $count)` must verify total event count
- **FR-4.35**: Assertions must be chainable for fluent test writing

## Non-Functional Requirements

- **NFR-4.1**: Audit logging must add less than 5ms overhead when async queuing enabled
- **NFR-4.2**: Synchronous audit calls must complete within 200ms
- **NFR-4.3**: Failed audit API calls must not block or crash the request
- **NFR-4.4**: Audit data must never include sensitive fields (passwords, tokens)
- **NFR-4.5**: Queued audit events must retry on failure with exponential backoff

## Dependencies

### Prerequisites

- Phase 1 complete (service provider, facade, WorkOS SDK configured)
- Phase 2 complete (authentication, user context)
- Phase 3 recommended (organization context enriches logs)

### Outputs for Next Phase

- AuditLogger service for manual event logging
- AuditMiddleware for automatic route logging
- Auditable contract for models
- Test assertions for verifying audit behavior
- Integration with WorkOSFake for testing

## Acceptance Criteria

- [ ] `WorkOS::audit('user.updated', [...])` sends event to WorkOS API
- [ ] Audit events include correct actor, context, and timestamp
- [ ] Middleware `workos.audit:document.viewed` logs on successful response
- [ ] Middleware infers action from HTTP method when not specified
- [ ] Audit logging is no-op when feature flag disabled
- [ ] API failures are caught and logged, not thrown
- [ ] Model implementing `Auditable` can be passed as target
- [ ] `WorkOS::fake()->assertAudited('user.updated')` passes when logged
- [ ] `WorkOS::fake()->assertNotAudited('user.deleted')` passes when not logged
- [ ] Assertions are chainable: `->assertAudited(...)->assertAuditedCount(1)`
- [ ] All unit and feature tests passing

---

*Review this PRD and provide feedback before spec generation.*
