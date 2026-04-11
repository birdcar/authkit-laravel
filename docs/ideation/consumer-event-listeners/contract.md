# Consumer Event Listeners Contract

**Created**: 2026-04-11
**Confidence Score**: 96/100
**Status**: Draft
**Supersedes**: None

## Problem Statement

AuthKit Laravel dispatches typed Laravel events (`WorkOSUserCreated`, `WorkOSOrganizationUpdated`, etc.) when WorkOS state changes arrive via webhooks or the Events API. Consumers who want to handle these events — syncing to their own models, sending notifications, triggering jobs — must currently wire everything manually: write a listener class, register it in a service provider with `Event::listen()`, and re-implement common patterns like resolving a user model from a WorkOS ID.

The package ships three built-in sync listeners that handle the basics (upsert user/org, sync memberships), but consumers who need different behavior (e.g., also sync `avatar_url`, send a welcome email, soft-delete on `WorkOSUserDeleted`) have no way to selectively replace or extend them. The only option is to register additional listeners that potentially duplicate work, or to ignore the package listeners entirely and start from scratch.

This friction makes WorkOS events feel like an escape hatch rather than a first-class Laravel feature. The package's goal is to make everything about WorkOS feel native to Laravel — that means consumers should be able to write event listeners as naturally as they write any other Laravel listener.

## Goals

1. **Consumers can write WorkOS event listeners using standard Laravel patterns** — create a class in `app/Listeners/`, type-hint the event, and Laravel's auto-discovery handles registration. No manual `Event::listen()` needed.
2. **A helper trait provides Laravel-native convenience methods** — model resolution, audit logging, structured logging, and transaction support so consumers don't re-implement common patterns.
3. **Per-event listener config lets consumers replace or disable built-in listeners** — granular control without all-or-nothing. Keep the org sync, replace the user sync, disable membership sync.
4. **An artisan command scaffolds listeners with correct imports and type hints** — interactive multi-select for which events to handle, generates the class with trait usage and proper structure.

## Success Criteria

- [ ] A consumer can create `app/Listeners/MyHandler.php` with a `handle(WorkOSUserCreated $event)` method and it is auto-discovered by Laravel without any manual registration
- [ ] The helper trait provides `resolveUser()`, `resolveOrganization()` methods that find models by WorkOS ID using the configured model classes
- [ ] The helper trait provides `audit()` that wraps `WorkOS::audit()` with event context
- [ ] The helper trait provides `logEvent()` for structured logging with event type and ID
- [ ] The helper trait provides `withinTransaction()` for wrapping listener logic in a DB transaction
- [ ] Setting `'sync.listeners.WorkOSUserCreated' => null` in config disables the built-in user sync for that event
- [ ] Setting `'sync.listeners.WorkOSUserCreated' => App\Listeners\MySync::class` in config replaces the built-in listener
- [ ] Omitting an event from `sync.listeners` keeps the package default
- [ ] `php artisan workos:make-listener` interactively prompts for event selection and class name, generates a valid listener file
- [ ] Generated listeners include the helper trait and correct event imports
- [ ] All existing package tests continue to pass (no regression)
- [ ] PHPStan level 8 passes on all new code

## Scope Boundaries

### In Scope

- `HandlesWorkOSEvents` trait with model resolution, audit, logging, and transaction helpers
- Per-event listener configuration in `config/workos.php` under a `sync.listeners` key
- Service provider changes to read listener config and conditionally register built-in vs consumer listeners
- `workos:make-listener` artisan command with interactive event selection
- Tests for the trait, config system, and artisan command
- Documentation in the workbench showing the consumer pattern

### Out of Scope

- Custom event discovery beyond Laravel's built-in system — no custom attributes, no directory scanning
- Event subscriber classes — consumers use individual listeners per Laravel convention
- Queued listener helpers beyond what Laravel's `ShouldQueue` already provides — no custom queue wrappers
- Broadcasting/WebSocket integration — separate concern
- Migration from the old `Events\Webhooks\*` namespace — already shipped in v0.5.0

### Future Considerations

- `#[ListensForWorkOSEvent]` attribute for explicit event binding (evaluated and rejected for now — Laravel's type hints serve the same purpose)
- Event subscriber pattern for consumers who want one class handling many events via named methods
- Wildcard listener support for `WorkOSEventReceived` consumers who want all events
- `workos:list-events` command showing all available events and their current listener bindings

## Execution Plan

### Dependency Graph

```
Phase 1: HandlesWorkOSEvents trait + per-event listener config
  └── Phase 2: workos:make-listener artisan command (blocked by Phase 1)
```

### Execution Steps

**Strategy**: Sequential (Phase 2 depends on Phase 1's trait)

1. **Phase 1** — Core: trait + config _(blocking)_
   ```bash
   /execute-spec docs/ideation/consumer-event-listeners/spec-phase-1.md
   ```

2. **Phase 2** — Scaffolding: artisan make command _(blocked by Phase 1)_
   ```bash
   /execute-spec docs/ideation/consumer-event-listeners/spec-phase-2.md
   ```
