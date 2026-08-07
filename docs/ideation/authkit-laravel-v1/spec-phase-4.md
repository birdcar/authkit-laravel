# Implementation Spec: AuthKit Laravel v1 - Phase 4 (Events Pipeline & Webhooks)

**Contract**: ./contract-data.json
**Shared conventions reference**: ./spec-template-feature-area.md _(Phase 4 is a full standalone spec per that file's own header — it does not consume the delta template, but this spec follows its canonical naming for guards/middleware/facades and extends the middleware-alias table with one new entry)_
**Estimated Effort**: XL

**Risk**: HIGH (per contract `execution.phases`). This phase owns the package's only long-running process, its only inter-process locking, its only signal handling, and the at-least-once delivery contract every other projection-writing phase depends on. Get the cursor-commit-after-dispatch ordering wrong and every downstream phase (RBAC via projections, Audit Logs, Admin Portal domain verification) silently drifts from WorkOS.

## Prerequisites From Earlier Phases (assumptions this spec makes)

Phases 1–3 were not yet written when this spec was generated (phasing runs in parallel). This spec depends on the following interfaces existing by the time Phase 4 is implemented. **The execute-spec agent must confirm these against the merged Phase 1–3 code before wiring the components below** and adjust call sites if the real names differ — this is the single biggest risk to this spec's accuracy and is called out again in Open Items.

| Assumed from | Interface this spec calls | Contract basis |
|---|---|---|
| Phase 1 | `Authkit\Authkit\WorkosClientManager::client(): \WorkOS\WorkOS` — config-driven singleton (API key, client ID, base URL override for emulate, injectable Guzzle handler) | contract-data.json phase 1 notes: "WorkOS client container binding (config-driven, base-URL override for emulate, injectable Guzzle handler)" |
| Phase 1 | `config/authkit.php` (renamed from `config/authkit-laravel.php`) | contract-data.json phase 1 notes |
| Phase 2 | App's user model has a `workos_id` column (unique) and is resolvable via `config('auth.providers.users.model')` | contract scope row "Users: workos_id migration + user trait" |
| Phase 3 | `config('authkit.organizations.model')` resolves to the app's org model (polymorphic — the app names it `Team`, `Workspace`, etc.), which has `workos_id` (unique) and `external_id` columns | contract scope row "Organizations: HasWorkosOrganization trait... workos_id ↔ external_id" |
| Phase 3 | Package-owned `Authkit\Authkit\Models\OrganizationDomain` (table `organization_domains`) and `Authkit\Authkit\Models\OrganizationMembership` (table `organization_memberships`), each with `workos_id` (unique) | contract scope row "domains projection" + projection whitelist ("org domains, memberships") |

If Phase 3 lands with different config keys or model locations, only the eight projection-refresh listeners in this spec need updating — the poller, mapper, cursor, and webhook path have no dependency on those names.

## Technical Approach

This phase builds the package's primary sync transport (a durable, cursor-persisted poller against the WorkOS Events API) and its secondary low-latency transport (webhooks), unified behind one mapping layer so both transports dispatch the exact same Laravel event objects. The architecture has four layers:

1. **Wire → domain object**: `WorkosEventMapper` turns a `WorkOS\Resource\EventSchema` (the SDK's event shape, identical whether it came from `listEvents()` or a verified webhook body) into either one of 14 bounded typed events under `Events\Workos\*` or the `GenericWorkosEvent` fallback. This is the ONE place that knows the bounded-type list from the contract's decision log — both transports call it, so "same Laravel event objects across transports" is structural, not a convention someone can drift from.
2. **Durable delivery (primary)**: `authkit:work` is a long-running Artisan command that loops: acquire/renew a cache lock (singleton enforcement), fetch one page via `Events::listEvents()`, dispatch every event in the page through Laravel's event bus, and — only after the entire page dispatched without throwing — persist the cursor. This ordering is the whole ballgame: it produces **at-least-once** delivery (a crash after dispatch but before the cursor save replays the batch on restart) and never **at-most-once** (which would silently drop events). Every listener this phase registers, and every listener `make:workos-listener` scaffolds, must therefore be idempotent — this is stated in code comments, not just this doc.
3. **Low-latency delivery (secondary)**: `Route::workosWebhooks($uri)` registers a POST route behind a signature-verification middleware (wrapping the SDK's hand-maintained `WebhookVerification`, `t=/v1=` HMAC, 180s default tolerance) that hands a verified, decoded payload to a controller. The controller runs the payload through the exact same `WorkosEventMapper` and dispatches through the exact same event bus. Webhooks are a latency shortcut, not a second source of truth — the poller is what keeps state durably correct across outages.
4. **Fan-out**: eight projection-refresh listeners (upsert/delete pairs for users, organizations, organization domains, organization memberships) are registered by the package itself in `AuthkitServiceProvider::boot()`, so the declared projections stay fresh with zero app code. Apps add their own listeners for anything else (including every out-of-scope event type) via `make:workos-listener`.

The one piece of real research this phase required: **whether `AuthkitServiceProvider` can register `authkit:work` into `php artisan dev` automatically**. As of Laravel 13.16.0 (June 2026), `Illuminate\Foundation\DevCommands` explicitly refuses auto-registration from the `vendor/` directory — this is a deliberate framework design choice, not a bug to route around. This spec therefore ships a documented copy-paste recipe for the app's own `AppServiceProvider::boot()` plus a `composer-dev`-script fallback for apps on Laravel 12 or pre-13.16 Laravel 13, and records both as **Open Item #1** rather than pretending the package can self-register.

## Decisions Considered and Rejected

_Carried from the contract in full (relevance to a HIGH RISK, cross-cutting phase is unclear enough that the template's "include all" rule applies)._

- **RBAC reads come from JWT claims (zero HTTP per check); FGA is the explicit escalation path via the Check API** — rejected: sync WorkOS roles/permissions into local spatie-style tables. Claims already ride the access token so checks are free; local tables duplicate canonical WorkOS state and drift.
- **Breadth-complete v1: all 16 scope areas ship in the first version at usable-core depth; phases are build order, not releases** — rejected: release-tiered rollout (v0.1 auth core, features in v0.2+). Ecosystem-substitution logic plus explicit stakeholder decision ("literally all of the features I listed are our MVP").
- **Custom `workos` guard with the AuthKit sealed session cookie as canonical auth state** — rejected: exchange code then hydrate Laravel's standard session guard. WorkOS must remain the session source of truth for both authn and authz.
- **Truth bar: emulate-backed Pest feature tests in CI, Guzzle MockHandler fakes only where emulate lacks coverage** — rejected: SDK fakes only. Wire fidelity where possible; emulate v0.6.0 covers events/webhooks solidly, so this phase is emulate-backed except where noted.
- **Local Eloquent rows are declared projections (user, org, domains, memberships) with `workos_id` ↔ `external_id` linking, refreshed by the events pipeline** — rejected: no local state / read-through API calls per request. This phase IS the refresh mechanism the decision assumes exists; it must not introduce any table beyond the whitelist plus the events cursor.
- **Feature Flags ship as a first-party laravel/pennant driver** — rejected: standalone facade. Not directly relevant to Phase 4 but retained per "include all when unclear."
- **Directory Sync: prefer WorkOS-managed directory provisioning; ship events-pipeline listener recipes for custom mapping; no dedicated module** — rejected: full dsync provisioning module. Directly relevant: `make:workos-listener` generating a `GenericWorkosEvent`-typed stub for `dsync.*` types IS the "recipe" this decision promises.
- **Full org context in v1** — rejected: read-only org context. Not directly Phase 4, retained per inclusion rule.
- **Stay on Pest 4 with PHP ^8.3 floor** — rejected: Pest 5. Governs this phase's test suite (Pest 4, no parallel-runner assumptions baked into signal-based tests — see Testing Requirements).
- **Credentials read from config only; `env()` is never read outside config files** — rejected: runtime `env()` reads like the SDK's own fallback. Directly relevant: every new config key in this phase (`authkit.events.*`, `authkit.webhooks.*`) reads via `config()` in `src/`, `env()` only inside `config/authkit.php`.
- **Events API sidecar is the primary sync transport; webhooks are optional low-latency triggers sharing the same Laravel event objects** — rejected: webhooks-primary sync. This is this phase's core architecture, not a peripheral note.
- **Auth flows exposed both as registered routes and as form-request helpers** — rejected: routes-only surface. Not directly Phase 4 (that's Phase 2), retained per inclusion rule.
- **Wire the Events worker and emulate into `php artisan dev`** — rejected: composer dev script only. Directly relevant and directly complicated by the DevCommands vendor-block finding above — see Open Item #1.
- **Widgets are excluded from v1 entirely** — rejected: widget token minting in MVP. Not relevant to Phase 4's event types (no widget-related events exist to type), retained per inclusion rule.
- **Phase 1 ends with an empirical AuthKit token audit** — rejected: assume the SDK's TODO values. Not directly Phase 4, retained per inclusion rule.
- **API Keys Guard and Connect & MCP phases depend on Organizations & Org Context** — rejected: original prereq graph. Not directly Phase 4, retained per inclusion rule.
- **FGA ships without caching** — rejected: default per-check cache in MVP. Not directly Phase 4, retained per inclusion rule.
- **Typed sidecar events are bounded to types feeding the declared projections + audit/domain-verification; everything else dispatches a generic `WorkosEvent`** — rejected: a typed Laravel event class per WorkOS event type. This IS Phase 4's typed-event boundary; the exact 14-type list in this spec is the binding narrowing of this decision (see Phase-Specific Direction below).
- **Quickstart criterion split... projection-boundary arch test added** — rejected: single judgment-only quickstart criterion. Directly relevant: this phase must not add any table beyond `workos_event_cursor` or the arch test (built in Phase 13) fails against this phase's own migration.
- **v1 targets the Full tier** — rejected: MVP-only v1. Not directly Phase 4, retained per inclusion rule.
- **Express run executes directly on main; recovery anchor `git reset --hard 4d04d0b`** — rejected: isolation branch. Process note, not a design constraint on this phase's code.

## Feedback Strategy

**Inner-loop command**: `vendor/bin/pest --filter=EventsWorker` (seconds; exercises the mapper, batch processor, and cursor against emulate without touching webhooks or the generator)

**Playground**: `npx @workos/emulate` booted per the Phase 1 test harness (`EmulateServer::start()` / seeded `workos-emulate.config.yaml`), driven through scoped Pest feature suites. The one exception is the crash/resume simulation (see Testing Requirements), which uses a fake throwing listener rather than OS-level process signals — real `SIGKILL`-and-restart testing is not portable to the Windows CI runners this package must support.

**Known emulate quirk — do not call `.reset()` between cases**: the context brief flags that emulate's `.reset()` (used elsewhere to isolate test cases against a shared in-memory instance) breaks auth-event webhooks. This phase's entire suite runs against emulate and `WebhooksTest.php`/`EventsWorkerTest.php` are exactly the kind of suites that would otherwise reach for `.reset()` between cases to avoid state bleed. Mitigation: isolate cases by reseeding (fresh `workos-emulate.config.yaml` data, or a freshly-booted emulate instance per test file/process) rather than calling `.reset()` on a running instance; treat `.reset()` as off-limits for this phase's webhook/event test paths and record it as a known local-dev limitation if it resurfaces outside tests (e.g. a developer manually resetting a long-running local emulate instance while `authkit:work` or a webhook tunnel is pointed at it).

**Why this approach**: every component in this phase reduces to "fetch or receive an event → map it → dispatch it → (maybe) persist a cursor," so a scoped Pest suite against emulate is the tightest loop for four of the five iterative components; the fifth (the generator command) is validated by asserting on generated file contents via Testbench's in-memory app, which is also fast and requires no external process.

## File Changes

### New Files

| File Path | Purpose |
|---|---|
| `database/migrations/2026_04_01_000001_create_workos_event_cursor_table.php` | Single-row durable cursor table (`last_event_id`, `last_event_occurred_at`) |
| `src/Models/WorkosEventCursor.php` | Eloquent model wrapping the single-row cursor with `current()`/`commit()` |
| `src/Events/Workos/AbstractWorkosEvent.php` | Shared `id`/`payload`/`occurredAt` shape for all 14 bounded typed events |
| `src/Events/Workos/UserCreated.php` | Typed event for `user.created` |
| `src/Events/Workos/UserUpdated.php` | Typed event for `user.updated` |
| `src/Events/Workos/UserDeleted.php` | Typed event for `user.deleted` |
| `src/Events/Workos/OrganizationCreated.php` | Typed event for `organization.created` |
| `src/Events/Workos/OrganizationUpdated.php` | Typed event for `organization.updated` |
| `src/Events/Workos/OrganizationDeleted.php` | Typed event for `organization.deleted` |
| `src/Events/Workos/OrganizationDomainCreated.php` | Typed event for `organization_domain.created` |
| `src/Events/Workos/OrganizationDomainUpdated.php` | Typed event for `organization_domain.updated` |
| `src/Events/Workos/OrganizationDomainDeleted.php` | Typed event for `organization_domain.deleted` |
| `src/Events/Workos/OrganizationDomainVerified.php` | Typed event for `organization_domain.verified` |
| `src/Events/Workos/OrganizationDomainVerificationFailed.php` | Typed event for `organization_domain.verification_failed` |
| `src/Events/Workos/OrganizationMembershipCreated.php` | Typed event for `organization_membership.created` |
| `src/Events/Workos/OrganizationMembershipUpdated.php` | Typed event for `organization_membership.updated` |
| `src/Events/Workos/OrganizationMembershipDeleted.php` | Typed event for `organization_membership.deleted` |
| `src/Events/GenericWorkosEvent.php` | Fallback event (`type`, `id`, `payload`, `occurredAt`) for every WorkOS event type outside the bounded 14 |
| `src/Events/WorkosEventMapper.php` | Maps an `EventSchema` to a typed event or `GenericWorkosEvent`; shared by the poller and the webhook controller |
| `src/Events/EventBatchProcessor.php` | Fetches one page (via `after` cursor or `rangeStart` fallback), dispatches it, commits the cursor only on full success |
| `src/Console/Commands/WorkCommand.php` | `authkit:work` — the long-running poller: lock, signal trap, loop, `--once` |
| `src/Console/Commands/MakeWorkosListenerCommand.php` | `make:workos-listener` generator (typed or generic stub) |
| `src/Console/Commands/stubs/workos-listener.typed.stub` | Stub for a listener bound to one of the 14 typed events |
| `src/Console/Commands/stubs/workos-listener.generic.stub` | Stub for a listener bound to `GenericWorkosEvent` |
| `src/Http/Middleware/VerifyWorkosWebhookSignature.php` | Verifies `WorkOS-Signature` (`t=`/`v1=` HMAC, tolerance-checked) and stashes the decoded payload |
| `src/Http/Controllers/WorkosWebhookController.php` | Maps the verified payload through `WorkosEventMapper` and dispatches it |
| `src/Listeners/Workos/UpsertUserProjection.php` | Idempotent upsert on `user.created`/`user.updated` |
| `src/Listeners/Workos/DeleteUserProjection.php` | Idempotent delete-if-exists on `user.deleted` |
| `src/Listeners/Workos/UpsertOrganizationProjection.php` | Idempotent upsert on `organization.created`/`organization.updated` |
| `src/Listeners/Workos/DeleteOrganizationProjection.php` | Idempotent delete-if-exists on `organization.deleted` |
| `src/Listeners/Workos/UpsertOrganizationDomainProjection.php` | Idempotent upsert on domain created/updated/verified/verification_failed |
| `src/Listeners/Workos/DeleteOrganizationDomainProjection.php` | Idempotent delete-if-exists on `organization_domain.deleted` |
| `src/Listeners/Workos/UpsertOrganizationMembershipProjection.php` | Idempotent upsert on membership created/updated |
| `src/Listeners/Workos/DeleteOrganizationMembershipProjection.php` | Idempotent delete-if-exists on `organization_membership.deleted` |
| `tests/Feature/EventsWorkerTest.php` | Mapper + batch fetch + cursor commit + rangeStart fallback + unknown-type fallback |
| `tests/Feature/EventsWorkerResumeTest.php` | The named crash/resume suite (success criterion) |
| `tests/Feature/SinglePollerLockTest.php` | Cache-lock singleton enforcement |
| `tests/Feature/WebhooksTest.php` | Signature verification, tolerance boundary, controller mapping |
| `tests/Feature/ProjectionRefreshListenersTest.php` | Idempotency of all eight listeners |
| `tests/Feature/MakeWorkosListenerTest.php` | Generator: typed stub, generic stub, `--force` behavior |
| `tests/Unit/WorkosEventMapperTest.php` | Pure unit coverage of the 14-type map + fallback, no HTTP |

### Modified Files

| File Path | Changes |
|---|---|
| `src/AuthkitServiceProvider.php` | `boot()`: register the 8 projection listeners via `Event::listen()`; register `Route::macro('workosWebhooks', ...)`; register the `authkit.webhook` middleware alias; console-only: register `WorkCommand` and `MakeWorkosListenerCommand` via `$this->commands([...])` |
| `config/authkit.php` (Phase 1's renamed config file) | Add `events` array (`poll_interval`, `batch_limit`, `backfill_minutes`, `lock_ttl`) and `webhooks` array (`secret`, `tolerance`) — see Data Model |
| `workbench/routes/web.php` | Add `Route::workosWebhooks('workos/webhooks');` — demonstrates the macro with zero SDK references, feeds Phase 13's grep enforcement |
| `workbench/app/Providers/WorkbenchServiceProvider.php` | Add a commented `DevCommands::register('authkit:work', 'authkit-events')` example in `boot()` — the documented fallback recipe, inert until an app opts in |
| `docs/quickstart.md` (created by an earlier phase; append here) | Add the `WORKOS_WEBHOOK_SECRET` env var and one line on running `php artisan authkit:work` in a separate process |

### Deleted Files

None.

## Implementation Details

### 1. Cursor Storage (migration + `WorkosEventCursor` model)

**Pattern to follow**: `database/migrations/2026_01_01_000000_create_authkit_laravel_placeholder_table.php` (anonymous-class migration style already in the repo)

**Overview**: One table, one row, read at the top of every poll cycle and written once per successful batch. This is the ONLY new table this phase introduces — the contract's projection-boundary decision explicitly whitelists "sync bookkeeping (events cursor)," so this table is pre-approved, but nothing else may be added here.

```php
// database/migrations/2026_04_01_000001_create_workos_event_cursor_table.php
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workos_event_cursor', function (Blueprint $table) {
            $table->id();
            $table->string('last_event_id')->nullable();
            $table->timestamp('last_event_occurred_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workos_event_cursor');
    }
};
```

```php
// src/Models/WorkosEventCursor.php
final class WorkosEventCursor extends Model
{
    protected $table = 'workos_event_cursor';
    protected $guarded = [];
    protected $casts = ['last_event_occurred_at' => 'immutable_datetime'];

    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    public function commit(string $eventId, \DateTimeImmutable $occurredAt): void
    {
        $this->forceFill([
            'last_event_id' => $eventId,
            'last_event_occurred_at' => $occurredAt,
        ])->save();
    }
}
```

**Key decisions**:
- Single-row semantics are enforced by application logic (`firstOrCreate([])` always returns/creates row 1), not a DB constraint — safe because the cache lock guarantees only one poller process ever calls `commit()`.
- No separate migration-publish tag beyond the existing `authkit-migrations` tag Phase 1 already registers.

**Implementation steps**:
1. `php artisan make:migration create_workos_event_cursor_table` then replace the generated body with the schema above (table name is literal per the phase-specific requirement, not the Eloquent-plural default).
2. `php artisan make:model WorkosEventCursor` then add `current()`/`commit()`.

**Feedback loop**: Skipped — thin Eloquent wrapper around one row. Correctness is verified indirectly through the Poller's feedback loop (`EventsWorkerTest`/`EventsWorkerResumeTest` assert cursor state after every batch), not a dedicated loop.

### 2. Typed Domain Events (`Events/Workos/*`)

**Overview**: 14 immutable DTOs (plus one abstract base) for the bounded event-type set from the contract's decision log, narrowed to the exact strings confirmed against live WorkOS docs (2026-08-06):

`user.created`, `user.updated`, `user.deleted`, `organization.created`, `organization.updated`, `organization.deleted`, `organization_domain.created`, `organization_domain.updated`, `organization_domain.deleted`, `organization_domain.verified`, `organization_domain.verification_failed`, `organization_membership.created`, `organization_membership.updated`, `organization_membership.deleted`.

```php
// src/Events/Workos/AbstractWorkosEvent.php
abstract class AbstractWorkosEvent
{
    public function __construct(
        public readonly string $id,
        /** @var array<string, mixed> raw `data` from the WorkOS EventSchema */
        public readonly array $payload,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}

// src/Events/Workos/UserCreated.php
final class UserCreated extends AbstractWorkosEvent {}
// ...13 more, identical one-line bodies, distinguished only by class name
```

**Key decisions**:
- Every typed event carries the same three properties — no per-type shape (e.g. no `UserCreated::$email`). Listeners pull fields out of `$payload`. This keeps 14 classes from becoming 14 bespoke shapes to maintain, and matches the "usable-core, not gold-plated" doctrine already applied elsewhere in the contract (FGA caching, dsync module).
- No class exposes `\WorkOS\Resource\EventSchema` or any other `\WorkOS\` type on its public API — `$payload` is a plain array, `$occurredAt` is a plain `\DateTimeImmutable`. This is what keeps app-side listener code (including generated ones) free of SDK references, feeding the goal's grep-enforced check.
- Not marked `ShouldQueue`/queued by default — dispatch is synchronous within the poller/webhook request. An app wanting async listeners implements `ShouldQueue` on its own listener, same as any Laravel event.

**Implementation steps**: Create the abstract base by hand (no generator fits this shape), then 14 one-line subclasses.

**Feedback loop**: Skipped — pure DTOs (config/enum/DTO carve-out). Correctness is exercised through `WorkosEventMapperTest` (component 4) and `ProjectionRefreshListenersTest` (component 6), not a dedicated loop for the DTOs themselves.

### 3. `GenericWorkosEvent` Fallback

```php
// src/Events/GenericWorkosEvent.php
final class GenericWorkosEvent
{
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        /** @var array<string, mixed> */
        public readonly array $payload,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
```

**Key decisions**: Deliberately NOT a subclass of `AbstractWorkosEvent` — it carries `$type` (the raw WorkOS event string, e.g. `dsync.user.created`, `role.created`) which the bounded typed events don't need since their class name already encodes the type. This is the recipe the contract's Directory Sync decision promises ("events-pipeline listener recipes for custom mapping... no dedicated module") — any event WorkOS ever adds, including entire product areas out of this package's scope, flows through this one class without a code change here.

**Feedback loop**: Skipped — DTO. Covered by `WorkosEventMapperTest`'s fallback-branch assertions.

### 4. `WorkosEventMapper`

**Overview**: The single source of truth for "which WorkOS event types are typed." Both the poller and the webhook controller call this and nothing else.

```php
// src/Events/WorkosEventMapper.php
final class WorkosEventMapper
{
    /** @var array<string, class-string<AbstractWorkosEvent>> */
    private const TYPE_MAP = [
        'user.created' => UserCreated::class,
        'user.updated' => UserUpdated::class,
        'user.deleted' => UserDeleted::class,
        'organization.created' => OrganizationCreated::class,
        'organization.updated' => OrganizationUpdated::class,
        'organization.deleted' => OrganizationDeleted::class,
        'organization_domain.created' => OrganizationDomainCreated::class,
        'organization_domain.updated' => OrganizationDomainUpdated::class,
        'organization_domain.deleted' => OrganizationDomainDeleted::class,
        'organization_domain.verified' => OrganizationDomainVerified::class,
        'organization_domain.verification_failed' => OrganizationDomainVerificationFailed::class,
        'organization_membership.created' => OrganizationMembershipCreated::class,
        'organization_membership.updated' => OrganizationMembershipUpdated::class,
        'organization_membership.deleted' => OrganizationMembershipDeleted::class,
    ];

    public function map(\WorkOS\Resource\EventSchema $event): AbstractWorkosEvent|GenericWorkosEvent
    {
        $class = self::TYPE_MAP[$event->event] ?? null;

        if ($class === null) {
            return new GenericWorkosEvent(
                type: $event->event,
                id: $event->id,
                payload: $event->data,
                occurredAt: $event->createdAt,
            );
        }

        return new $class(
            id: $event->id,
            payload: $event->data,
            occurredAt: $event->createdAt,
        );
    }
}
```

**Key decisions**:
- Takes the SDK's `EventSchema` directly (this is the ONE class in the package allowed to import a `\WorkOS\` type, since it is the deliberate boundary between wire shape and domain shape — same pattern the guard/session layer uses for the SDK's `SessionManager` in Phase 2).
- `EventSchema::fromArray()` (public, static, part of the SDK) is reused by the webhook controller to build the same input this mapper expects from a verified webhook body — this is what makes "same event objects across transports" true by construction rather than by convention.

**Implementation steps**:
1. `php artisan make:class Events/WorkosEventMapper` (or hand-create; no generator ships one for plain classes).
2. Write the const map and `map()` exactly as above — the map's left-hand strings are the acceptance criteria for this component.

**Feedback loop**:
- **Playground**: `tests/Unit/WorkosEventMapperTest.php` — no HTTP, no emulate; construct `EventSchema` instances directly.
- **Experiment**: call `map()` with each of the 14 bounded type strings and assert the exact class returned; call it with an out-of-scope type (`dsync.user.created`) and assert a `GenericWorkosEvent` with `type === 'dsync.user.created'`; call it with a type WorkOS might add tomorrow (`some_future.event`) and assert the same graceful fallback (never a thrown exception).
- **Check command**: `vendor/bin/pest tests/Unit/WorkosEventMapperTest.php`

### 5. `authkit:work` Poller (`WorkCommand` + `EventBatchProcessor`)

**Overview**: The long-running process. `EventBatchProcessor` holds the fetch/dispatch/commit logic as a directly-testable unit; `WorkCommand` is the thin Artisan wrapper adding the loop, the cache lock, and signal handling.

```php
// src/Events/EventBatchProcessor.php
final class EventBatchProcessor
{
    public function __construct(
        private readonly WorkosClientManager $clients,
        private readonly WorkosEventMapper $mapper,
    ) {}

    /**
     * Fetch and dispatch the next batch, then commit the cursor.
     * Returns the number of events processed (0 = nothing new).
     *
     * @throws \Throwable if any listener throws mid-batch — the cursor is
     *                     deliberately NOT committed in that case (see Failure Modes).
     */
    public function runOnce(WorkosEventCursor $cursor, int $batchLimit): int
    {
        $page = $this->fetchPage($cursor, $batchLimit);

        if ($page->data === []) {
            return 0;
        }

        foreach ($page->data as $event) {
            event($this->mapper->map($event));
        }

        $last = $page->data[array_key_last($page->data)];
        $cursor->commit($last->id, $last->createdAt);

        return count($page->data);
    }

    private function fetchPage(WorkosEventCursor $cursor, int $batchLimit): \WorkOS\PaginatedResponse
    {
        $events = $this->clients->client()->events();

        if ($cursor->last_event_id !== null) {
            try {
                return $events->listEvents(
                    after: $cursor->last_event_id,
                    limit: $batchLimit,
                    order: \WorkOS\Resource\PaginationOrder::Asc,
                );
            } catch (\WorkOS\Exception\BadRequestException|\WorkOS\Exception\NotFoundException) {
                // Stored cursor no longer resolvable (retention window — see Open Items).
                // Fall through to the rangeStart path below.
            }
        }

        return $events->listEvents(
            limit: $batchLimit,
            order: \WorkOS\Resource\PaginationOrder::Asc,
            rangeStart: $this->rangeStart($cursor),
        );
    }

    private function rangeStart(WorkosEventCursor $cursor): string
    {
        $from = $cursor->last_event_occurred_at
            ?? now()->subMinutes((int) config('authkit.events.backfill_minutes', 5))->toImmutable();

        // WorkOS rejects date-only and microsecond formats — exactly 3-digit ms UTC.
        return $from->format('Y-m-d\TH:i:s.v\Z');
    }
}
```

```php
// src/Console/Commands/WorkCommand.php
#[AsCommand(name: 'authkit:work')]
final class WorkCommand extends Command
{
    protected $signature = 'authkit:work {--once : Process a single batch and exit}';
    protected $description = 'Poll the WorkOS Events API and dispatch mapped Laravel events (at-least-once — keep listeners idempotent).';

    private bool $shouldStop = false;

    public function __construct(private readonly EventBatchProcessor $processor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->trap([SIGTERM, SIGINT], function () { $this->shouldStop = true; });

        $ttl = (int) config('authkit.events.lock_ttl', 90);
        $lock = Cache::lock('authkit-events-worker', $ttl);

        if (! $lock->get()) {
            $this->error('Another authkit:work process already holds the lock.');
            return self::FAILURE;
        }

        try {
            do {
                if (! $lock->refresh($ttl)) {
                    $this->error('Lost the authkit-events-worker lock mid-run — exiting to avoid a split-brain poller.');
                    return self::FAILURE;
                }

                try {
                    $processed = $this->processor->runOnce(
                        WorkosEventCursor::current(),
                        (int) config('authkit.events.batch_limit', 100),
                    );
                } catch (\WorkOS\Exception\WorkOSException $e) {
                    $this->error("WorkOS Events API error: {$e->getMessage()}");
                    if ($this->option('once')) {
                        return self::FAILURE;
                    }
                    sleep((int) config('authkit.events.poll_interval', 5));
                    continue;
                }

                $this->line($processed > 0 ? "Dispatched {$processed} event(s)." : 'No new events.');

                if ($this->option('once')) {
                    break;
                }

                if ($processed === 0 && ! $this->shouldStop) {
                    sleep((int) config('authkit.events.poll_interval', 5));
                }
            } while (! $this->shouldStop);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
```

**Key decisions**:
- `order: PaginationOrder::Asc` + `after: <cursor>` walks forward through history in creation order — this is what makes "commit the last item's id" a valid forward-moving cursor rather than an arbitrary page boundary.
- The lock is renewed (`$lock->refresh($ttl)`) at the top of every loop iteration, not just acquired once at start — `refresh()` (not `get()`/`acquire()`) is required here because every driver's `acquire()` (e.g. `ArrayLock::acquire()`, `RedisLock::acquire()` via `SET NX`, `CacheLock::acquire()` via `Cache::add()`) succeeds only when the key does NOT already exist, regardless of who owns it, so calling `get()` again on a lock this same process already holds would always return `false` and the daemon would exit `FAILURE` after its first batch, every run. `refresh()` (`ArrayLock::refresh()`, `vendor/laravel/framework/src/Illuminate/Cache/ArrayLock.php`) explicitly checks `isOwnedByCurrentProcess()` before extending the TTL, which is what correctly renews ownership so a batch that runs longer than the TTL doesn't silently let a second instance start, and still detects real TTL loss under load (another process's lock, or an expired-and-reclaimed one) so we fail loudly (`FAILURE`) instead of racing another poller.
- Signal handling only sets a flag checked BETWEEN batches — never mid-dispatch. A signal arriving mid-batch is indistinguishable from a hard crash mid-batch and is covered by the same at-least-once/idempotency contract as component 1's crash case, not by special-casing signals.
- `--once` exists purely for testability and for apps that prefer to run the poller from a scheduler/cron rather than as a daemon; it is not a substitute for the daemon in production (poll latency would be bounded by the scheduler's own interval).
- Catching `WorkOSException` broadly around the OUTER call (not the narrow `BadRequestException|NotFoundException` around the `after` fetch) means a real outage (5xx, timeout, rate limit) retries with a sleep rather than being misdiagnosed as a stale cursor.

**Implementation steps**:
1. `php artisan make:command WorkCommand` then rewrite per the signature above (the generator's default stub is a fine starting skeleton).
2. Hand-create `EventBatchProcessor` (no generator fits a plain service class).
3. Wire both into the container — `EventBatchProcessor` needs no explicit binding (Laravel autowires its two constructor dependencies); register `WorkCommand::class` in `AuthkitServiceProvider::boot()`'s console-only block via `$this->commands([...])`.

**Feedback loop**:
- **Playground**: emulate booted locally, `tests/Feature/EventsWorkerTest.php` and `tests/Feature/EventsWorkerResumeTest.php` (see Testing Requirements for the crash-simulation strategy).
- **Experiment**: empty batch (cursor untouched); 1-event batch (cursor advances to that event's id); 100-event batch (SDK's documented cap, `batch_limit` respected); a listener that throws on event 6 of 10, followed by a clean re-run of the same batch (resume — asserts zero missed, zero duplicate side effects); a `BadRequestException` thrown from the `after` call (asserts the `rangeStart` fallback path fires with the exact `Y-m-d\TH:i:s.v\Z` format); the daemon path itself (no `--once`) run against the `array` cache driver with a small `poll_interval` and at least two empty batches before a signal stops it — assert it completes at least two loop iterations and does NOT exit `FAILURE`, which is the regression guard for lock renewal (`$lock->refresh($ttl)`) actually renewing ownership on the SAME lock instance rather than re-acquiring, since neither this suite's other cases nor `SinglePollerLockTest` (which only exercises `--once`, returning before a second renewal call) would catch a regression back to `$lock->get()` here.
- **Check command**: `vendor/bin/pest --filter=EventsWorker` and, isolated, `vendor/bin/pest --filter=EventsWorkerResume`

### 6. Single-Poller Cache Lock

Covered as part of component 5's implementation (the `Cache::lock()` calls in `WorkCommand::handle()`), but called out separately because it has its own named success criterion and its own test file.

**Feedback loop**:
- **Playground**: `tests/Feature/SinglePollerLockTest.php`, using the `array` cache driver (Laravel's array driver supports atomic locks and needs no external service — required for the Windows CI lane).
- **Experiment**: manually acquire `Cache::lock('authkit-events-worker', 90)` in the test (simulating "worker A already running"), then invoke `php artisan authkit:work --once`; assert exit code is `FAILURE` and — via a Guzzle `MockHandler` history spy — assert **zero** HTTP requests were made (worker B never touches the WorkOS API at all).
- **Check command**: `vendor/bin/pest --filter=SinglePollerLock`

### 7. Webhook Ingestion (`Route::workosWebhooks` macro + `VerifyWorkosWebhookSignature` + `WorkosWebhookController`)

**Overview**: Three pieces tested together as one request/response path, since that's how they're actually exercised.

```php
// AuthkitServiceProvider::boot()
$this->app['router']->aliasMiddleware('authkit.webhook', VerifyWorkosWebhookSignature::class);

Route::macro('workosWebhooks', function (string $uri = 'workos/webhooks'): \Illuminate\Routing\Route {
    return Route::post($uri, WorkosWebhookController::class)
        ->middleware('authkit.webhook')
        ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class)
        ->name('authkit.webhooks');
});
```

```php
// src/Http/Middleware/VerifyWorkosWebhookSignature.php
final class VerifyWorkosWebhookSignature
{
    public function __construct(private readonly WorkosClientManager $clients) {}

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $secret = config('authkit.webhooks.secret');

        if (blank($secret)) {
            throw new \RuntimeException(
                'The [authkit.webhooks.secret] config value is required to verify WorkOS webhook signatures. Set WORKOS_WEBHOOK_SECRET in your .env file.'
            );
        }

        try {
            $payload = $this->clients->client()->webhookVerification()->verifyEvent(
                eventBody: $request->getContent(),
                eventSignature: (string) $request->header('WorkOS-Signature'),
                secret: $secret,
                tolerance: (int) config('authkit.webhooks.tolerance', 180),
            );
        } catch (\InvalidArgumentException) {
            abort(401, 'Invalid WorkOS webhook signature.');
        }

        $request->attributes->set('authkit.webhook.payload', $payload);

        return $next($request);
    }
}
```

```php
// src/Http/Controllers/WorkosWebhookController.php
final class WorkosWebhookController
{
    public function __construct(private readonly WorkosEventMapper $mapper) {}

    public function __invoke(Request $request): JsonResponse
    {
        $eventSchema = \WorkOS\Resource\EventSchema::fromArray(
            $request->attributes->get('authkit.webhook.payload')
        );

        event($this->mapper->map($eventSchema));

        return response()->json(['received' => true]);
    }
}
```

**Key decisions**:
- The middleware reads `$request->getContent()` — the raw, unparsed body — and passes it untouched to `verifyEvent()`. HMAC verification is byte-exact; calling `$request->json()`/`->all()` first and re-serializing would break signatures on any payload where key order or whitespace differs from what WorkOS sent.
- `verifyEvent()` (not the lower-level `verifyHeader()` + manual `json_decode`) is used because it does both steps in one SDK call and returns the decoded array directly — less code, same guarantee.
- CSRF is explicitly excluded in the macro itself (not left to the app to remember), since a webhook has no browser session or CSRF token regardless of which routes file the app registers it in. The excluded class is `\Illuminate\Foundation\Http\Middleware\PreventRequestForgery` — the class the `web` middleware group actually applies as of `laravel/framework` v13.23.0 (`vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php`). `ValidateCsrfToken`/`VerifyCsrfToken` are now `@deprecated` subclasses of `PreventRequestForgery`, never applied by default; `withoutMiddleware()` (`Router::resolveMiddleware()`, `vendor/laravel/framework/src/Illuminate/Routing/Router.php`) only drops an applied middleware when it exactly matches the excluded name or the applied class `isSubclassOf` the excluded one — since `PreventRequestForgery` is the parent and is not a subclass of its own deprecated child, excluding `ValidateCsrfToken` (or `VerifyCsrfToken`) would silently fail to exclude anything, and every real webhook POST (no session, no CSRF token) would be rejected with `419` before ever reaching `VerifyWorkosWebhookSignature`.
- The controller re-hydrates `EventSchema::fromArray()` from the verified payload and hands it to the SAME `WorkosEventMapper` the poller uses — this is the mechanism that makes "same Laravel event objects as the sidecar" true.
- A missing `authkit.webhooks.secret` throws rather than silently skipping verification — there is no code path where an unsigned or wrongly-signed payload can reach the controller.

**Implementation steps**:
1. `php artisan make:middleware VerifyWorkosWebhookSignature`, then implement per above.
2. `php artisan make:controller WorkosWebhookController --invokable`, then implement per above.
3. Register the alias and macro in `AuthkitServiceProvider::boot()`.
4. Add `Route::workosWebhooks('workos/webhooks');` to `workbench/routes/web.php` for a working example with zero SDK imports.

**Feedback loop**:
- **Playground**: `tests/Feature/WebhooksTest.php`, using Pest's Laravel plugin (`$this->postJson()`) against the workbench route, with signatures hand-computed the same way `WebhookVerification::computeSignature()` does (`hash_hmac('sha256', "{$timestamp}.{$body}", $secret)`).
- **Experiment**: valid signature + fresh timestamp → `200` + `Event::assertDispatched(UserCreated::class)`; tampered body with an otherwise-valid signature → `401`, nothing dispatched; timestamp 181s old → `401` (verifies the exact tolerance boundary); timestamp 179s old → `200` (verifies the boundary doesn't reject valid-but-close events); `authkit.webhooks.secret` unset → the `RuntimeException` surfaces (asserted by message, not by a specific HTTP status). Additionally, because `\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::handle()` unconditionally bypasses itself whenever `app()->runningInConsole() && app()->runningUnitTests()` — true for every Pest run — the `$this->postJson()` cases above cannot by themselves prove the CSRF exclusion in the macro is correct; add a dedicated assertion (with `App::runningUnitTests()` forced `false` via a partial mock/swap, or by asserting directly on the resolved route's `Route::excludedMiddleware()`/the middleware pipeline `Router::gatherRouteMiddleware()` produces for the webhook route) that `PreventRequestForgery` is actually excluded from the resolved pipeline, so a regression back to excluding `ValidateCsrfToken`/`VerifyCsrfToken` can't hide behind the test-mode CSRF bypass.
- **Check command**: `vendor/bin/pest --filter=Webhook`

### 8. Projection-Refresh Listeners

**Overview**: Eight listeners, registered by the package (not the app), keeping the four declared projections in sync. Every one follows the same two-listener-per-resource shape: an upsert listener bound to the resource's created/updated (and, for domains, verified/verification_failed) events, and a delete-if-exists listener bound to the resource's deleted event.

```php
// AuthkitServiceProvider::boot()
Event::listen([UserCreated::class, UserUpdated::class], UpsertUserProjection::class);
Event::listen(UserDeleted::class, DeleteUserProjection::class);
Event::listen([OrganizationCreated::class, OrganizationUpdated::class], UpsertOrganizationProjection::class);
Event::listen(OrganizationDeleted::class, DeleteOrganizationProjection::class);
Event::listen([
    OrganizationDomainCreated::class,
    OrganizationDomainUpdated::class,
    OrganizationDomainVerified::class,
    OrganizationDomainVerificationFailed::class,
], UpsertOrganizationDomainProjection::class);
Event::listen(OrganizationDomainDeleted::class, DeleteOrganizationDomainProjection::class);
Event::listen([OrganizationMembershipCreated::class, OrganizationMembershipUpdated::class], UpsertOrganizationMembershipProjection::class);
Event::listen(OrganizationMembershipDeleted::class, DeleteOrganizationMembershipProjection::class);
```

```php
// src/Events/Workos/AbstractWorkosEvent.php (addition — see component 2)
abstract class AbstractWorkosEvent
{
    public function __construct(
        public readonly string $id,
        /** @var array<string, mixed> raw `data` from the WorkOS EventSchema */
        public readonly array $payload,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}

    /**
     * The WorkOS id of the resource this event describes (e.g. the User,
     * Organization, Domain, or Membership id) — NOT `$this->id`, which is the
     * Event object's own id (`event_01H...`) and is different on every
     * delivery, even for repeat deliveries describing the same resource.
     * `EventSchema::$data` always carries the resource's own `id` field.
     */
    public function resourceId(): string
    {
        return $this->payload['id'];
    }
}

// src/Listeners/Workos/UpsertUserProjection.php (illustrative — exact payload keys/columns
// depend on Phase 2's finalized user projection; verify column names before implementing)
final class UpsertUserProjection
{
    public function handle(UserCreated|UserUpdated $event): void
    {
        config('auth.providers.users.model')::query()->updateOrCreate(
            ['workos_id' => $event->resourceId()],
            ['external_id' => $event->payload['external_id'] ?? null],
        );
    }
}

// src/Listeners/Workos/DeleteUserProjection.php
final class DeleteUserProjection
{
    public function handle(UserDeleted $event): void
    {
        config('auth.providers.users.model')::query()->where('workos_id', $event->resourceId())->delete();
    }
}
```

**Key decisions**:
- `$event->id` is the Event object's own id (`event_01H...`, from `EventSchema::$id` — see `vendor/workos/workos-php/lib/Resource/EventSchema.php`), which is different on every delivery, including repeat/replayed deliveries describing the same underlying resource. The actual User/Organization/Domain/Membership id lives inside `EventSchema::$data` (surfaced here as `$event->payload`) and must be read via the `resourceId()` accessor added to `AbstractWorkosEvent`, never `$event->id`. All eight listeners key their `workos_id` upserts/deletes off `resourceId()`.
- `updateOrCreate` keyed on `workos_id` (from `resourceId()`) is the idempotency mechanism — re-delivering the same `UserCreated` twice writes the same row twice, never two rows. A unique DB constraint on `workos_id` (assumed present from Phase 2/3's migrations) is the backstop if application logic is ever bypassed.
- Delete listeners always operate on a query (`->where(...)->delete()`), never `findOrFail()->delete()` — deleting a row that was never created locally (create-then-delete inside one poll gap, or a webhook race — see Failure Modes row 12) is a no-op, not an exception.
- The organization/domain/membership listeners resolve their target model via `config('authkit.organizations.model')` and the package's own `OrganizationDomain`/`OrganizationMembership` models (Phase 3 assumption — see Prerequisites).

**Implementation steps**:
1. `php artisan make:listener UpsertUserProjection --event="Authkit\Authkit\Events\Workos\UserCreated"` (repeat per listener; the `--event` flag only needs one of the two/four events the listener handles — widen the type-hint to a union by hand afterward).
2. Implement each per the pattern above.
3. Register all eight in `AuthkitServiceProvider::boot()`.

**Feedback loop**:
- **Playground**: `tests/Feature/ProjectionRefreshListenersTest.php`, dispatching typed events directly via `event()` against the Testbench SQLite database — no poller, no HTTP involved.
- **Experiment**: dispatch `UserCreated` twice with an identical payload → assert exactly one row; dispatch `OrganizationMembershipDeleted` for a membership never created locally → assert no exception and zero rows; dispatch `OrganizationDomainVerified` → assert the domain projection's verification field flips without touching unrelated columns; dispatch `UserCreated` then `UserDeleted` for the SAME resource id (`payload['id']`) but with DIFFERENT, realistic event ids (`$event->id`, e.g. `event_01H...aaa` then `event_01H...bbb`, mirroring two distinct real deliveries) → assert the row created by the first dispatch is actually removed by the second — this is the regression guard for keying deletes off `resourceId()` rather than the Event object's own `$event->id`.
- **Check command**: `vendor/bin/pest --filter=ProjectionRefresh`

### 9. `make:workos-listener` Generator

**Pattern to follow**: `vendor/laravel/framework/src/Illuminate/Foundation/Console/ListenerMakeCommand.php` (core `make:listener` — same `GeneratorCommand` extension, same `--event` option, same typed/untyped stub-switch shape)

**Overview**: Same generator pattern Laravel itself uses for `make:listener`, but `--event` resolves against this package's bounded 14-name list instead of the app's `Events/` folder, and falls back to `GenericWorkosEvent` (not a bare `object` type-hint) for anything else.

```php
// src/Console/Commands/MakeWorkosListenerCommand.php
#[AsCommand(name: 'make:workos-listener')]
final class MakeWorkosListenerCommand extends GeneratorCommand
{
    protected $name = 'make:workos-listener';
    protected $description = 'Create a new listener for a WorkOS sidecar/webhook event';
    protected $type = 'Listener';

    private const TYPED_EVENTS = [
        'UserCreated', 'UserUpdated', 'UserDeleted',
        'OrganizationCreated', 'OrganizationUpdated', 'OrganizationDeleted',
        'OrganizationDomainCreated', 'OrganizationDomainUpdated', 'OrganizationDomainDeleted',
        'OrganizationDomainVerified', 'OrganizationDomainVerificationFailed',
        'OrganizationMembershipCreated', 'OrganizationMembershipUpdated', 'OrganizationMembershipDeleted',
    ];

    protected function getStub(): string
    {
        return in_array($this->option('event'), self::TYPED_EVENTS, true)
            ? $this->resolveStubPath('/stubs/workos-listener.typed.stub')
            : $this->resolveStubPath('/stubs/workos-listener.generic.stub');
    }

    protected function resolveStubPath($stub): string
    {
        return file_exists($custom = $this->laravel->basePath(trim($stub, '/')))
            ? $custom
            : __DIR__.$stub;
    }

    protected function buildClass($name): string
    {
        $event = $this->option('event');
        $eventClass = in_array($event, self::TYPED_EVENTS, true)
            ? "Authkit\\Authkit\\Events\\Workos\\{$event}"
            : GenericWorkosEvent::class;

        return str_replace(
            ['{{ event }}', '{{ eventNamespace }}'],
            [class_basename($eventClass), $eventClass],
            parent::buildClass($name),
        );
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Listeners';
    }

    protected function getOptions(): array
    {
        return [
            ['event', 'e', InputOption::VALUE_OPTIONAL, 'A bounded WorkOS event short name (e.g. UserCreated); omit for the generic fallback'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the listener if it already exists'],
        ];
    }
}
```

`src/Console/Commands/stubs/workos-listener.typed.stub` and `workos-listener.generic.stub` follow Laravel's own `listener.stub`/`listener.typed.stub` shape (see `vendor/laravel/framework/src/Illuminate/Foundation/Console/stubs/`), with one addition to both: a doc-comment stating

> WorkOS delivers events at-least-once (the sidecar and webhooks can both redeliver the same event after a crash or retry, each delivery carrying its own distinct `$event->id`). Keep this handler idempotent — upsert or delete-if-exists keyed by the resource's own id (`$event->resourceId()` for typed events, `$event->payload['id']` for `GenericWorkosEvent`) — never key on `$event->id`, which is the Event object's own id and differs on every delivery, and never assume exactly-once delivery.

and the generic stub additionally shows a commented example: `// if ($event->type === 'dsync.user.created') { ... }`.

**Key decisions**:
- Reusing the framework's own generator base class (`Illuminate\Console\GeneratorCommand`) rather than writing a bespoke file-writer keeps this command consistent with every other `make:*` command an app developer already knows (force flag, path resolution, namespace resolution, "already exists" check all come free).
- An unrecognized `--event` value (typo, or a real WorkOS type like `dsync.user.created` that's intentionally out of the bounded set) falls back to the generic stub rather than erroring — the generator should never block a developer from listening to something.

**Implementation steps**:
1. Hand-create the command (no `make:command` scaffold matches a `GeneratorCommand` subclass); base the skeleton directly on `ListenerMakeCommand`.
2. Write the two stub files.
3. Register the command in `AuthkitServiceProvider::boot()`'s console-only block.

**Feedback loop**:
- **Playground**: `tests/Feature/MakeWorkosListenerTest.php`, using `$this->artisan('make:workos-listener', [...])` against Testbench's app skeleton.
- **Experiment**: `--event=UserCreated` → generated file imports and type-hints `Authkit\Authkit\Events\Workos\UserCreated` and contains the idempotency comment; no `--event` → imports `GenericWorkosEvent`; `--event=TotallyMadeUp` → also falls back to the generic stub (never errors); running the same name twice without `--force` fails (mirrors core `make:listener`'s `alreadyExists` check).
- **Check command**: `vendor/bin/pest --filter=MakeWorkosListener`

### 10. `php artisan dev` / composer-dev Integration Recipe

**Overview**: Documentation-only component — there is no package code to register `authkit:work` (or emulate) into `php artisan dev` automatically. Research against live Laravel docs (2026-08-06) confirmed: `php artisan dev` shipped in Laravel 13.16.0 (June 2026) as `Illuminate\Foundation\DevCommands`, configured from the APP's own `AppServiceProvider::boot()` via `DevCommands::artisan('command', 'name')->color()` / `DevCommands::register('raw command', 'name')->color()`, and **`DevCommands` explicitly refuses to auto-register commands sourced from the `vendor/` directory** — a deliberate framework design choice, not a gap to work around.

**Documented recipe A (Laravel ≥13.16 — preferred)**: the app copies this into its own `AppServiceProvider::boot()`:

```php
use Illuminate\Foundation\DevCommands;

DevCommands::artisan('authkit:work', 'authkit-events')->purple();
DevCommands::register('npx @workos/emulate', 'workos-emulate')->orange();
```

**Documented recipe B (fallback — Laravel 12, or Laravel 13 pre-13.16)**: a `composer.json` `dev` script the app adds itself, following the same `concurrently`-based shape Laravel's own skeleton used before `artisan dev` existed:

```json
"dev": [
    "Composer\\Config::disableProcessTimeout",
    "npx concurrently -c \"#93c5fd,#fca5a5,#fdba74\" \"php artisan serve\" \"php artisan authkit:work\" \"npx @workos/emulate\" --names=serve,authkit-events,workos-emulate --kill-others"
]
```

Both recipes are placed in the package's quickstart documentation and demonstrated (recipe A, commented out pending the app's own Laravel version) in `workbench/app/Providers/WorkbenchServiceProvider.php`.

**Feedback loop**: Skipped — no executable package code. Tracked as Open Item #1, since `artisan dev` is four months old at spec-write time and its extension API could still shift before this phase is implemented.

## Data Model

### Schema Changes

```sql
-- New table (the only one this phase introduces)
CREATE TABLE workos_event_cursor (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    last_event_id VARCHAR(255) NULL,
    last_event_occurred_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
```

No indexes beyond the primary key — the table holds exactly one row, read/written once per poll cycle.

### Config Shape

```php
// config/authkit.php — additive keys
return [
    // ...existing Phase 1/2/3 keys...

    'events' => [
        'poll_interval' => env('AUTHKIT_EVENTS_POLL_INTERVAL', 5),      // seconds between empty polls
        'batch_limit' => env('AUTHKIT_EVENTS_BATCH_LIMIT', 100),        // matches the SDK's documented 1-100 cap
        'backfill_minutes' => env('AUTHKIT_EVENTS_BACKFILL_MINUTES', 5), // first-run rangeStart lookback
        'lock_ttl' => env('AUTHKIT_EVENTS_LOCK_TTL', 90),                // seconds; must exceed one batch's worst-case dispatch time
    ],

    'webhooks' => [
        'secret' => env('WORKOS_WEBHOOK_SECRET'),
        'tolerance' => env('AUTHKIT_WEBHOOKS_TOLERANCE', 180),           // matches WebhookVerification's own default
    ],
];
```

## API Design

### New Endpoints

| Method | Path (app-chosen via macro; default shown) | Description |
|---|---|---|
| `POST` | `/workos/webhooks` | Verifies the `WorkOS-Signature` header, maps the payload to a typed/generic Laravel event, dispatches it, returns `{"received": true}` |

### Request/Response Examples

```
POST /workos/webhooks
WorkOS-Signature: t=1735689600000, v1=3f2504e...
Content-Type: application/json

{"id":"event_01H...","event":"user.created","data":{...},"created_at":"2026-08-06T12:00:00.000Z"}
```

```json
// 200 response
{"received": true}
```

```json
// 401 response (bad signature or stale timestamp)
{"message": "Invalid WorkOS webhook signature."}
```

## Testing Requirements

### Unit Tests

| Test File | Coverage |
|---|---|
| `tests/Unit/WorkosEventMapperTest.php` | All 14 bounded type strings map to the correct class; unknown/future types fall back to `GenericWorkosEvent` |

**Key test cases**:
- Each of the 14 `TYPE_MAP` entries produces the exact expected class with `id`/`payload`/`occurredAt` copied through unchanged.
- An event type not in the map (`dsync.user.created`, a synthetic `not.a.real.type`) produces `GenericWorkosEvent` with `type` set correctly.
- Empty `payload`/`data` array is accepted without error (data-shadow case).

### Integration Tests

| Test File | Coverage |
|---|---|
| `tests/Feature/EventsWorkerTest.php` | Batch fetch (empty/1/100 events), cursor commit timing, `rangeStart` fallback trigger and exact format |
| `tests/Feature/EventsWorkerResumeTest.php` | Named crash/resume suite (success criterion `--filter=EventsWorkerResume`) |
| `tests/Feature/SinglePollerLockTest.php` | Lock contention rejects a second poller with zero API calls made |
| `tests/Feature/WebhooksTest.php` | Valid/invalid/stale-timestamp signatures; missing-secret fail-fast; controller dispatches the mapped event |
| `tests/Feature/ProjectionRefreshListenersTest.php` | Idempotent upsert/delete-if-exists for all 4 resources |
| `tests/Feature/MakeWorkosListenerTest.php` | Typed stub, generic stub, unrecognized `--event`, `--force` behavior |

**Key scenarios**:
- **EventsWorkerResume (the crash simulation)**: Seed emulate with 10 sequential events for a mix of typed and generic types. Register a temporary test listener on one of the typed events that throws on its 6th invocation within the run. Call `EventBatchProcessor::runOnce()` directly (not through the artisan command's loop) — assert it throws, and assert the cursor is UNCHANGED from before the call (still pointing at whatever preceded this batch), while asserting the first 5 events' idempotent side effects (e.g., resulting row counts) DID apply, since those listeners ran and committed to the database before the 6th one threw. This is "kill worker mid-batch" without OS signals — the crash IS the uncaught exception, which is exactly what a `SIGKILL` mid-dispatch would also leave behind (partial listener side effects, no cursor commit). Remove the throwing behavior and call `runOnce()` again with the SAME cursor state — assert the SAME 10-event batch is re-fetched (because the cursor didn't move), assert all 10 events' side effects are now correctly applied, and assert row counts show **zero duplicates** for the first 5 events that already ran once (idempotent upsert) — this is "restart... assert zero missed + zero duplicate side effects."
- **rangeStart format**: assert the string passed to `listEvents(rangeStart: ...)` matches `/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/` exactly (3-digit ms) — construct a deliberately-wrong 2-digit-ms or date-only string in a separate assertion and confirm the emulate/MockHandler fixture would reject it, documenting the format is load-bearing, not cosmetic.
- **Webhook tolerance boundary**: sign a body with a timestamp 179s in the past → 200; 181s in the past → 401. Uses `WebhookVerification::computeSignature()` directly in the test to build valid signatures rather than hand-rolling HMAC logic twice.
- **Single-poller lock**: MockHandler request-history count is asserted to be exactly 0 for the rejected worker, not just "an error occurred" — proves the lock check happens BEFORE any WorkOS API call.

### Manual Testing

- [ ] Run `php artisan authkit:work` against a real (non-emulate) WorkOS test environment for a few minutes and confirm the process logs "No new events." on empty polls without erroring.
- [ ] Trigger a real user creation in the WorkOS dashboard and confirm the local projection row appears within one `poll_interval`.
- [ ] Configure a real webhook endpoint pointed at a tunneled `/workos/webhooks` and confirm a dashboard-triggered event arrives with a 200 response faster than the next poll cycle would have delivered it.
- [ ] Send SIGTERM to a running `authkit:work` process mid-batch (large seeded backlog) on a Unix dev machine and confirm it finishes the current batch, commits the cursor, and exits 0 — then confirm restarting picks up exactly where it left off.

## Error Handling

| Error Scenario | Handling Strategy |
|---|---|
| `authkit.webhooks.secret` unset | Throw `RuntimeException` naming the exact config key, before any signature math runs |
| Malformed `WorkOS-Signature` header (wrong part count) | `WebhookVerification::getTimestampAndSignatureHash()` throws `InvalidArgumentException`; middleware converts to `401` |
| `listEvents()` throws a non-cursor-related `WorkOSException` (5xx/timeout/rate-limit) | Poller logs and sleeps `poll_interval`, then retries the same cursor position on the next loop — no data loss, no crash |
| Cursor row missing on first boot | `WorkosEventCursor::current()` self-initializes via `firstOrCreate([])` rather than requiring a seed |
| Two `authkit:work` processes started | Second process's `Cache::lock()->get()` returns `false`; it logs and exits `FAILURE` immediately, never calling the WorkOS API |

## Failure Modes

| Component | Failure Mode | Trigger | Impact | Mitigation |
|---|---|---|---|---|
| Poller | Crash between dispatch and cursor commit | Process killed/OOM/deploy restart after some listeners ran but before `cursor.commit()` | At-least-once duplicate delivery — the same batch replays on restart | Idempotent upsert/delete-if-exists listeners (loudly documented in the generator stub); `EventsWorkerResume` suite asserts zero duplicate side effects |
| Poller | Unknown/future WorkOS event type | WorkOS ships a new event type, or an out-of-scope type (e.g. `dsync.user.created`) arrives | Would crash if the mapper required exhaustive matching | `WorkosEventMapper` defaults unmapped `event` strings to `GenericWorkosEvent`; never throws on an unrecognized type |
| Poller | WorkOS Events API unreachable mid-poll | Network partition, WorkOS outage, SDK's own 429/5xx retries exhausted | `listEvents()` throws `WorkOSException` | Caught in `WorkCommand`; logs, sleeps `poll_interval`, retries next cycle; cursor untouched so nothing is lost |
| Poller | Stale cursor outside retention window | Long outage; stored `last_event_id` no longer resolvable via `after` (retention period is UNVERIFIED — see Open Items) | `listEvents(after: ...)` returns `BadRequestException`/`NotFoundException` | Caught narrowly; falls back to `rangeStart` built from `last_event_occurred_at` in the exact `Y-m-d\TH:i:s.v\Z` format |
| Poller | Two instances started concurrently | Deploy overlap, supervisor double-start, manual run colliding with a supervised one | Both would poll/dispatch independently — doubled duplicate-delivery risk, wasted API quota | `Cache::lock('authkit-events-worker')` acquired non-blocking at start and renewed every batch; loser exits `FAILURE` immediately without an API call; `SinglePollerLockTest` |
| Poller | SIGTERM/SIGINT during an active batch | Deploy restart or supervisor stop signal while a batch is dispatching | Naive handling could abort mid-listener (partial writes, no cursor commit) | Signal flag checked only between batches via `Command::trap()`; current batch always finishes and commits before exit |
| Poller | `pcntl` unavailable (Windows) | Windows PHP builds never ship `pcntl`; CI matrix includes Windows | `trap()` silently no-ops — SIGTERM/SIGINT can't be intercepted; only a hard kill is possible, with no graceful drain | Acknowledged — matches Laravel's own documented `trap()` behavior; at-least-once idempotency (not graceful drain) is the correctness guarantee on Windows; `--once` avoids daemonizing entirely where that matters |
| `WorkosEventCursor` | Missing row on first boot | Fresh install, migration ran, no seed | Naive `first()` would return `null` | `firstOrCreate([])` self-initializes |
| Webhook middleware | Missing/blank secret config | `WORKOS_WEBHOOK_SECRET` never set | Every webhook would silently mis-verify, or worse, a future refactor could accidentally skip verification | Fails fast with a `RuntimeException` naming `authkit.webhooks.secret`, not a generic 401 or a WorkOS-side error |
| Webhook middleware | Replay / stale timestamp | Captured signature replayed, or delivery delayed past 180s tolerance | `verifyEvent()` throws `InvalidArgumentException` | Middleware catches it and returns `401`; no event dispatched, no listener side effects |
| Webhook middleware | Body re-serialization before signature check | A proxy or earlier middleware rewrites JSON (key order/whitespace) before this middleware runs | HMAC computed over re-serialized bytes won't match WorkOS's signature over the original bytes | Middleware reads `$request->getContent()` (raw string) and is registered first in the macro's own middleware stack, before anything else can touch the body |
| Poller + Webhook controller (shared) | Out-of-order cross-transport delivery (race) | A `.deleted` webhook (low-latency) arrives before the poller has processed an earlier `.created` for the same resource, or vice versa | Projection could transiently show incorrect state | Acknowledged, not engineered around this phase — the projection-boundary arch test forbids adding new bookkeeping tables/columns to solve this; delete-if-exists + upsert keep state eventually consistent once the Events API (primary, ordered) catches up; the contract's Stretch-tier State API reconciliation command is the sanctioned future fix |
| Projection listeners | Duplicate dispatch creates two local rows | Same event delivered twice (poller replay, or both transports for one resource) | Data integrity — two Eloquent rows for one WorkOS resource | `updateOrCreate` keyed on `workos_id`; a unique DB constraint on `workos_id` (assumed from Phase 2/3) is the backstop |
| Projection listeners | Delete-for-missing-row | `.deleted` arrives for a resource never locally created (create-then-delete inside one poll gap, or a webhook race) | A naive `findOrFail()`-then-delete would throw | Always delete-if-exists via a query builder `->delete()`, never `findOrFail()` |
| Generator | Unrecognized `--event` value | Typo, or a real WorkOS type intentionally outside the bounded 14 | Generator can't resolve a typed class | Falls back to the generic stub; never errors |
| Test harness (emulate) | `.reset()` breaks auth-event webhooks (known emulate quirk, per context brief) | A test or local dev workflow calls emulate's `.reset()` between cases/sessions while this phase's webhook or events suites are running against it | Webhook signature verification / event delivery against emulate silently misbehaves after a reset, producing flaky or false-failing `WebhooksTest`/`EventsWorkerTest` runs | This phase's Feedback Strategy avoids `.reset()` entirely for webhook/event paths — reseed (fresh config or a freshly-booted instance) instead of resetting a running one; documented as a known local-dev limitation, not engineered around in package code |
| `php artisan dev` integration | Vendor-package auto-registration blocked | `Illuminate\Foundation\DevCommands` explicitly refuses registration sourced from `vendor/` (by design) | `AuthkitServiceProvider::boot()` cannot call `DevCommands::register()` itself | Documented Open Item; ships a copy-paste app-side snippet plus a `composer dev` fallback, both in docs and demonstrated (inert/commented) in the workbench |

## Validation Commands

```bash
# Static analysis
composer analyse

# Formatting check
composer lint:check

# Type coverage (must stay 100%)
composer test:types

# This phase's suites, scoped (seconds each)
vendor/bin/pest tests/Unit/WorkosEventMapperTest.php
vendor/bin/pest --filter=EventsWorker
vendor/bin/pest --filter=EventsWorkerResume
vendor/bin/pest --filter=SinglePollerLock
vendor/bin/pest --filter=Webhook
vendor/bin/pest --filter=ProjectionRefresh
vendor/bin/pest --filter=MakeWorkosListener

# No env() outside config files (must return no matches -> exit 1)
grep -rn 'env(' src/ --include='*.php'

# Full validation chain — must be green before commit
composer test
```

## Rollout Considerations

- **Feature flag**: None — the package ships no runtime feature-flag gate for itself; every phase lands green on `composer test` and is releasable as-is.
- **Monitoring**: Recommend apps log `authkit:work`'s stdout (batch size, "No new events." lines, and any caught `WorkOSException` messages) through their process supervisor (systemd/Supervisor/Laravel Cloud) so a stalled poller is visible from log volume alone — no bespoke metrics emitter is added in this phase (would be new state/complexity without a stated requirement).
- **Alerting**: Recommend the app's process supervisor alert on the `authkit:work` process exiting (any exit code) — a clean exit only happens via `--once` or a caught signal, both of which are intentional; an unexpected exit means either a lock loss (`FAILURE`) or an uncaught exception in a listener (a bug to fix, per the idempotency contract, not a condition to swallow).
- **Rollback plan**: `git revert` of this phase's commit(s), or reset to the contract's recorded anchor (`git reset --hard 4d04d0b`) if reverting mid-development. The one migration this phase adds is additive and reversible (`down()` drops the table cleanly) with no data-migration step, so rollback carries no cursor-position risk beyond re-running the first-run `rangeStart` backfill.

## Open Items

- [ ] **Confirm the Phase 1/2/3 interface names this spec assumes** (`WorkosClientManager::client()`, `config('authkit.organizations.model')`, `Authkit\Authkit\Models\OrganizationDomain`/`OrganizationMembership`) against the actual merged code before implementing the eight projection-refresh listeners and the webhook client-access calls — the poller, mapper, cursor, and generator have no dependency on these names and can proceed regardless.
- [ ] **Verify the `php artisan dev` / `DevCommands` extension mechanism against primary Laravel docs at implementation time.** This spec's Component 10 is based on web research (Laravel 13.16.0, June 2026) rather than a vendored source read, and the feature is recent enough that its public API could still change. Ship both documented recipes (A: app-side `DevCommands`, B: composer `dev` script) regardless of which one turns out current.
- [ ] **WorkOS Events API retention period is UNVERIFIED.** The `rangeStart` fallback in `EventBatchProcessor::fetchPage()` is deliberately defensive (catches `BadRequestException`/`NotFoundException` rather than assuming a specific outage duration), but confirming the real retention window would let the `backfill_minutes` default and the failure-mode documentation be precise instead of conservative.
- [ ] **Out-of-order cross-transport delivery (webhook vs. poller race) is accepted as a known, acknowledged limitation of this phase**, not solved with new bookkeeping state (the projection-boundary arch test forbids it). Confirm during Phase 13 acceptance testing that this is still an acceptable risk profile for v1, or promote the contract's Stretch-tier "State API reconciliation command" into a real phase if it isn't.
- [ ] **Exact `organization_domain.*` verification-state string values** (e.g. what `data.state` contains inside a `organization_domain.verified` payload) are UNVERIFIED per the context brief. This doesn't block this phase's typed events (which pass `payload` through untouched), but it DOES block Component 8's `UpsertOrganizationDomainProjection` listener body — confirm the exact field names/values against a real WorkOS domain-verification event before implementing that listener's payload-reading logic.

---

_This spec is ready for implementation. Follow the patterns and validate at each step._
