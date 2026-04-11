# Events API Worker Contract

**Created**: 2026-04-11
**Confidence Score**: 95/100
**Status**: Approved
**Supersedes**: None

## Problem Statement

The `workos:events-listen` command in authkit-laravel is fundamentally broken. It treats the WorkOS Events API (`GET /events`) as a Server-Sent Events stream when it is actually a REST polling endpoint with cursor-based pagination returning JSON. The current implementation has no cursor persistence (cannot resume after restart), uses recursive `handle()` on error (stack overflow risk), and is self-described as an "example implementation."

Package consumers cannot reliably sync WorkOS state changes (user CRUD, organization changes, directory sync, memberships) via the Events API. The WorkOS PHP SDK has no Events service class, so the package must call the API directly. WorkOS specifically recommends the Events API over webhooks for certain event categories like Directory Sync.

Additionally, the current config offers only a binary `webhooks.sync_enabled` toggle with no way to route different event categories through different sync mechanisms (webhooks vs Events API). Consumers who need both — webhooks for authentication events and Events API for directory sync — have no supported path.

## Goals

1. **Replace `workos:events-listen` with a correct polling worker** that calls `GET /events` with cursor-based pagination, processes events sequentially, and persists cursor state across restarts
2. **Enable hybrid sync routing** where consumers configure per-category (or per-event-type override) whether events flow through webhooks or the Events API
3. **Support long-lived background execution** — the command should run like `queue:work`: persistent process with graceful shutdown (SIGTERM/SIGINT), configurable poll interval, and automatic backoff when caught up
4. **Add a top-level `workos.events` config key** replacing `workos.webhooks.sync_enabled` with a proper routing and Events API configuration section
5. **Maintain event dispatch parity** — both webhooks and Events API dispatch identical typed events through the same `WebhookReceived` + `EVENT_MAP` system

## Success Criteria

- [ ] `workos:events-listen` polls `GET /events` with correct parameters (`after`, `events`, `limit`) and processes JSON responses
- [ ] Cursor (last processed event ID) persists in Laravel Cache across process restarts; configurable cache store
- [ ] Worker resumes from last cursor on restart — no duplicate processing or missed events
- [ ] `--since` flag allows bootstrapping from a specific ISO 8601 date on first run
- [ ] `config('workos.events.lookback_days')` provides a default lookback window when no cursor exists and `--since` is not passed
- [ ] Worker handles SIGTERM/SIGINT gracefully — finishes current event, persists cursor, exits cleanly
- [ ] `--once` flag polls a single page and exits (useful for cron-based setups or testing)
- [ ] Config supports per-category routing (`user`, `organization`, `organization_membership`, `dsync`, `session`, `authentication`) with per-event-type overrides
- [ ] Events API worker only requests event types configured for `events_api` routing
- [ ] WebhookController continues to work for event types configured for `webhooks` routing
- [ ] Both mechanisms dispatch through the same `WebhookReceived` + typed event class system
- [ ] Existing listeners (`SyncUserFromWebhook`, `SyncOrganizationFromWebhook`, `SyncMembershipFromWebhook`) work without modification regardless of sync source
- [ ] PHPStan level 8 passes, Pest tests cover the new command and config, Pint formatting clean
- [ ] Old `workos.webhooks.sync_enabled` config is removed; new `workos.events` config section takes its place

## Scope Boundaries

### In Scope

- Rewrite `EventsListenCommand` as a proper REST polling worker with cursor management
- New `workos.events` config section with routing, poll interval, lookback, and cache store settings
- Refactor `WorkOSServiceProvider::configureEventListeners()` to respect new routing config
- Event type filtering — worker only requests events it's configured to handle
- Graceful signal handling (SIGTERM/SIGINT)
- `--once` and `--since` command options
- Backoff when caught up (configurable sleep), immediate next page when more data
- Tests for command behavior, config routing, cursor persistence

### Out of Scope

- Per-organization worker parallelization — single worker is the recommended starting point per WorkOS docs
- Idempotency via `updated_at` comparison in listeners — existing listeners handle this implicitly through `findByWorkOSId` + update patterns; enhancing this is a separate concern
- Changes to existing webhook event classes or listener implementations
- Supervisor/systemd/Docker configuration for running the worker — that's deployment-specific documentation
- Adding new event types beyond what `EVENT_MAP` already supports

### Future Considerations

- Per-organization parallel workers (fan-out into queues)
- Dead letter / failed event tracking
- Metrics/monitoring integration (events processed, lag, errors)
- `workos:events-status` command showing cursor position, lag, last poll time
- Event replay / reprocessing from a given cursor or timestamp

## Execution Plan

### Dependency Graph

```
Phase 1: Config & Event Routing Infrastructure
  └── Phase 2: Events API Polling Worker  (blocked by Phase 1)
```

### Execution Steps

**Strategy**: Sequential

1. **Phase 1 — Config & Event Routing Infrastructure** _(blocking)_
   ```bash
   /execute-spec docs/ideation/events-api-worker/spec-phase-1.md
   ```

2. **Phase 2 — Events API Polling Worker** _(blocked by Phase 1)_
   ```bash
   /execute-spec docs/ideation/events-api-worker/spec-phase-2.md
   ```
