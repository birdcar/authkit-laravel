# Spec Delta: Phase 6 — Audit Logs & Admin Portal

**Follow `spec-template-feature-area.md`; inputs below.** This delta references that template for the shared technical approach, canonical conventions, test-path selection, feedback strategy, standard validation commands, and shared failure-mode prompts. Only phase-specific inputs, designs, and deviations are repeated here.

## 1. Phase Header

| Field | Value |
|---|---|
| Phase number/title | Phase 6 — Audit Logs & Admin Portal |
| Risk | medium (per contract) |
| Blocking | false (non-blocking — other feature-area phases do not depend on this one) |
| Prereq phases | Phase 4 — Events Pipeline & Webhooks (direct); transitively Phase 1 — Foundation & Client Binding, Phase 2 — Auth Core & Sealed Sessions, Phase 3 — Organizations & Org Context |
| Estimated effort | **L** (Large) |
| Spec path | `docs/ideation/authkit-laravel-v1/spec-phase-6.md` |

**Why Large, not Medium:** eight distinct components (trait + attribute, context resolver, facade, three passthrough surfaces, portal-link + enum, event listener), two async state machines (queued audit-event creation, export poll), a new exception hierarchy, four new Pest suites, and four cross-phase integration seams whose exact upstream shape isn't yet spec'd (see Open Items). No single component is hard, but the surface area and the cross-phase seams push this past Medium.

## 2. Scope Rows Implemented

Verbatim from `contract-data.json` → `scope.mvp`:

> **Audit Logs**: HasAuditLogs trait (lifecycle actions like post.created, metadata method, per-action opt-in via attribute/method); auto actor/org context; manual AuditLog facade; export + retention passthrough — *"Dead-simple audit logging is a stated headline feature"*

> **Admin Portal**: portal-link facade covering all 7 intents (sso, dsync, audit_logs, log_streams, domain_verification, certificate_renewal, bring_your_own_key); domain-verification events update the domains projection — *"Full-intent portal support is a stated requirement"*

No `scope.full` (Full-tier / Depth Extensions) items touch Audit Logs or Admin Portal — the Depth Extensions phase (Phase 12) covers invitations, JWT templates/CORS, Groups API, and FGA resource-graph/caching only. **No Full-tier items apply to this phase.**

## 3. Decisions Considered and Rejected

All 20 contract decision-log entries, filtered for relevance to this phase (per the delta requirement: carry all when unclear).

| Decision | Rejected Alternative | Relevance to Phase 6 |
|---|---|---|
| RBAC reads come from JWT claims, zero HTTP; FGA is explicit Check API | Sync roles/permissions into local tables | Not directly relevant — actor identity for audit events comes from the auth guard's user, not RBAC claims. Mentioned only because the same "no local shadow of canonical WorkOS state" instinct applies to Audit Logs: **this phase adds zero new WorkOS-shaped tables.** |
| Breadth-complete v1: all 16 areas at usable-core depth in v1 | Release-tiered rollout | Direct — confirms Audit Logs + Admin Portal ship now, at usable-core depth (not deferred, not deep). Sets the ceiling: passthroughs stay thin, no speculative wrapping. |
| Custom `workos` guard, sealed session cookie canonical | Exchange code → hydrate Laravel session guard | Direct — the audit-log actor resolver reads `Auth::guard('workos')->user()`, not a generic session guard. |
| Truth bar: emulate in CI, MockHandler only where emulate lacks coverage | SDK fakes only | Direct — governs Test-Path Selection for this phase (§7): emulate for create-event/schema/retention/portal-link, MockHandler for export. |
| Local Eloquent rows = declared projections only, refreshed by events | No local state / read-through per request | Direct — Audit Logs has **no local projection at all** (pure write-through to WorkOS); the domains projection touched by the verification listener is Phase 3's existing declared projection, not a new one. |
| Feature Flags ship as first-party Pennant driver | Standalone facade | Not relevant to this phase. |
| Directory Sync: no dedicated module, events recipes only | Full dsync provisioning module | Not relevant to this phase. |
| Full org context in v1: claims-resolved current org, switch route, tenant middleware | Read-only org context | Direct — the audit context resolver depends on Phase 3's current-org resolution to populate `organization_id` on every audit event; `createEvent`, `createExport`, and retention calls all require a non-null `organizationId` at the SDK signature level. |
| Stay on Pest 4, PHP ^8.3 floor | Pest 5 | Direct — this phase's four new Pest suites are written against Pest 4 conventions already in `tests/Pest.php`. |
| Credentials from config only, no runtime `env()` | Runtime `env()` fallback | Direct — the poll-interval/max-attempts config keys added by this phase (`config('authkit.audit_logs.*')`) follow this; no `env()` call appears in any new `src/` file. |
| Events API sidecar primary sync transport; webhooks share event objects | Webhooks-primary sync | Direct — the domain-verification listener (§4.8) consumes whichever transport (sidecar or webhook) dispatched the shared typed Laravel event; it does not care which fired it. |
| Auth flows exposed as both routes and form-request helpers | Routes-only | Not applicable — this phase adds no HTTP routes (headless passthroughs + one facade method); "two entry points" doesn't apply where there's only one entry point (PHP API) to begin with. |
| Wire events worker + emulate into `php artisan dev` | `composer dev` script only | Not relevant to this phase — no new long-running process introduced (export polling reuses existing queue workers). |
| Widgets excluded from v1 entirely | Widget token minting in MVP/Full | Direct (boundary-setting) — confirms Admin Portal in this phase is a **server-side link-mint only**; no widget/JS token minting is added here, matching `Authkit::portalLink()` returning a bare redirect URL for the admin to visit. |
| Phase 1 ends with an empirical AuthKit token audit (confirm iss/aud, claim defaults) | Assume SDK TODO values | Indirect — the audit context resolver's actor identification depends on the `workos_id`/session-claim shape that audit was meant to confirm. If the audit surfaced surprises about claim availability, they'd affect §4.2's design; assumed unaffected here (see Open Items). |
| API Keys Guard + Connect/MCP depend on Organizations & Org Context (SDK-signature-level org ID requirements) | Original auth-core-only prereq graph | Direct — **the same class of hidden dependency applies here.** `createEvent`, `createExport`, `getOrganizationAuditLogsRetention`/`updateOrganizationAuditLogsRetention`, and `generateLink` all require an organization ID at the SDK signature level, which is why this phase's prereq chain runs through Phase 3 (Organizations & Org Context) via Phase 4. |
| FGA ships without caching; opt-in cache is Full-tier | Default per-check cache | Not relevant to this phase. |
| Typed sidecar events bounded to projection-feeding + **audit/domain-verification** types; generic `WorkosEvent` fallback for the rest | Typed class per WorkOS event type | **Directly names this phase.** This is why `organization_domain.verified` / `organization_domain.verification_failed` get typed Laravel event classes from the Phase 4 pipeline instead of falling through to `GenericWorkosEvent` — the contract explicitly carves out "audit/domain-verification" as one of the two typed categories. |
| Quickstart split into mechanical + human-trial criteria; ProjectionBoundary arch test added | Single judgment-only criterion | Direct (secondary) — this phase must not introduce a table the ProjectionBoundary arch test doesn't whitelist; §5 confirms zero new migrations. |
| v1 targets Full tier: MVP + 5 depth extensions, folded into Phase 12 | MVP-only v1 | Direct (boundary-setting) — confirms this phase's two scope rows ship as specified with no depth extensions bundled in; Groups/JWT-templates/CORS/FGA-graph/FGA-cache are Phase 12's problem, not this phase's. |
| Express run executes directly on `main`; recovery anchor `git reset --hard e845a2f` | Isolation branch | Process-level only — not relevant to this phase's design. |

**Phase-specific decision (not one of the 21 contract decision-log entries above — added here because §4.5 makes a net-new design choice the contract doesn't address directly, and the table's own "carry all when unclear" rule shouldn't be read as license to skip weighing decisions the contract is silent on):**

| Decision | Rejected Alternative | Relevance to Phase 6 |
|---|---|---|
| Export passthrough auto-dispatches a self-requeuing poll job (`PollAuditLogExportJob`) and emits typed `AuditLogExportReady`/`AuditLogExportFailed` Laravel events after `createExport()` | Bare two-method passthrough — `createExport()`/`getExport()` only, consumer polls `getExport()` on their own schedule | The contract's "passthroughs stay thin, no speculative wrapping" ceiling (row above) argues for the bare passthrough by default, and this is the single largest piece of net-new orchestration logic in the phase. Kept anyway because WorkOS's export resource is inherently async (`pending`→`ready`/`error`/`expired`); a bare `getExport()` passthrough is unusable without *some* retry loop, so every consumer would independently re-implement the identical poll this job already automates. Treated as the minimum viable shape of the "export ... passthrough" scope line, not speculative wrapping — but flagged explicitly since, unlike every row above, it doesn't trace to a contract decision-log entry. |

## 4. Components

**Design note applying to every passthrough component (4.4, 4.5, 4.6):** `createSchema`, `listActions`, `listActionSchemas`, `createExport`, `getExport`, `getRetention`, and `setRetention` return the SDK's own readonly resource DTOs (`AuditLogSchema`, `WorkOS\PaginatedResponse<AuditLogAction|AuditLogSchema>`, `AuditLogExport`, `AuditLogsRetention`) unwrapped. This is deliberate: these are dashboard-adjacent management calls, not runtime-hot paths, and the contract's usable-core-depth ceiling ("breadth-complete v1... phases are build order, not releases") plus the "no speculative abstraction" rule in the template's Shared Technical Approach argue against inventing parallel wrapper DTOs for values nothing in the success criteria requires wrapped. The G2 FQCN grep (`grep -rE '(use |\\)WorkOS\\' workbench/`) only flags textual `use WorkOS\`/`\WorkOS\` references in workbench files; workbench code consuming these return values without importing the WorkOS class name stays compliant. `Authkit::portalLink()` (§4.7) is the one method in this phase that *is* wrapped down to a bare `string`, because the phase-specific direction names it as a first-class ergonomic accessor, not a passthrough.

### 4.1 `HasAuditLogs` trait + `#[AuditActions]` attribute

**Laravel mechanism:** Eloquent model trait with `bootHasAuditLogs()` static model-event listeners; PHP 8 attribute for declarative per-action overrides.
**SDK methods wrapped:** none directly — calls the package's own `AuditLog::log()` (§4.3).

**Key design:**

```php
// src/Attributes/AuditActions.php
namespace Authkit\Authkit\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AuditActions
{
    public function __construct(
        public readonly ?string $create = null,
        public readonly ?string $update = null,
        public readonly ?string $delete = null,
        public readonly ?string $archive = null,
        public readonly ?string $restore = null,
    ) {
    }
}
```

```php
// src/Concerns/HasAuditLogs.php
namespace Authkit\Authkit\Concerns;

trait HasAuditLogs
{
    protected static function bootHasAuditLogs(): void
    {
        static::created(fn ($model) => $model->recordAuditLogAction('create'));
        static::updated(fn ($model) => $model->recordAuditLogAction('update'));
        static::deleted(function ($model) {
            $usesSoftDeletes = method_exists($model, 'isForceDeleting');
            $isArchive = $usesSoftDeletes && ! $model->isForceDeleting();
            $model->recordAuditLogAction($isArchive ? 'archive' : 'delete');
        });
        static::restored(fn ($model) => $model->recordAuditLogAction('restore'));
    }

    /** Resolution order: $auditActions property > #[AuditActions] attribute > slug-based default. Partial overrides merge over defaults. */
    public function auditLogActions(): array { /* see prose below */ }

    /** Default action-name prefix. Override to change (e.g. "blog_post" instead of "post"). */
    public function auditLogSlug(): string
    {
        return \Illuminate\Support\Str::snake(class_basename($this));
    }

    /** Override to attach event metadata. Capped to WorkOS limits (50 keys, 500 chars/value) via MetadataSanitizer. */
    public function auditMetadata(): array
    {
        return [];
    }

    protected function recordAuditLogAction(string $lifecycle): void
    {
        \Authkit\Authkit\Facades\AuditLog::log(
            action: $this->auditLogActions()[$lifecycle],
            targets: [['id' => (string) $this->getKey(), 'type' => $this->auditLogSlug()]],
            metadata: $this->auditMetadata(),
        );
    }
}
```

`auditLogActions()` resolution: if the model declares a `$auditActions` property, its (possibly partial) array wins; else if the class carries `#[AuditActions(...)]`, that instance's non-null named args win; either source's values are merged **over** the slug-based defaults (`"{$slug}.create"`, etc.) so partial overrides are safe — a model can override only `delete` and still get default `create`/`update`/`archive`/`restore`.

**`deleted` → archive vs. delete logic** (the exact rule from the phase direction): a model *without* `SoftDeletes` has no `isForceDeleting()` method → `$usesSoftDeletes` is false → always `delete`. A model *with* `SoftDeletes` calling `->delete()` → `isForceDeleting()` is false at that point → `archive`. The same model calling `->forceDelete()` → `SoftDeletes::forceDelete()` sets `$forceDeleting = true` *before* invoking `delete()`, and only resets it *after* `delete()` returns — so `isForceDeleting()` reads `true` during the `deleted` event → `delete`.

**Implementation steps:**
1. `php artisan make:trait Concerns/HasAuditLogs` (Laravel's generic `make:trait` generator; if unavailable on the pinned Laravel version, fall back to `make:class` and adjust the stub by hand).
2. `php artisan make:class Attributes/AuditActions` (attribute classes have no dedicated generator).
3. Implement `auditLogActions()`/`auditLogSlug()`/`auditMetadata()`/`recordAuditLogAction()` per the design above.
4. Add workbench demo model exercising the default convention (§5).

**Feedback loop:**
- Playground: a workbench `Post` model (default convention, `SoftDeletes`) plus two throwaway test-file-local model classes mapped to the same `posts` table — one with a `$auditActions` property override, one with `#[AuditActions]` — to exercise precedence without extra migrations.
- Parameterized experiment: Pest dataset over `{create, update, soft-delete, force-delete, restore} × {default naming, property override, attribute override, partial override}`.
- Check command: `vendor/bin/pest --filter=HasAuditLogsTrait`

### 4.2 Audit context resolver (actor + organization)

**Laravel mechanism:** plain service class, singleton-bound; consumed by the `AuditLog` facade before any job is queued.
**SDK methods wrapped:** none — populates `WorkOS\Resource\AuditLogEventActor`/`AuditLogEventContext` fields that `CreateAuditLogEventJob` (§4.3) later constructs.

**Key design:**

```php
// src/AuditLogs/Support/ResolvedAuditContext.php
namespace Authkit\Authkit\AuditLogs\Support;

final readonly class ResolvedAuditContext
{
    public function __construct(
        public string $actorId,
        public string $actorType,
        public ?string $actorName,
        public string $organizationId,
        public string $location,
        public ?string $userAgent,
    ) {
    }
}
```

```php
// src/AuditLogs/Support/AuditActorResolver.php
namespace Authkit\Authkit\AuditLogs\Support;

use Authkit\Authkit\AuditLogs\Exceptions\MissingOrganizationContextException;
use Illuminate\Support\Facades\Auth;

final class AuditActorResolver
{
    public function resolve(
        ?string $organizationId = null,
        ?array $actorOverride = null,
    ): ResolvedAuditContext {
        $user = Auth::guard('workos')->user();

        $actorId = $actorOverride['id'] ?? $user?->workos_id ?? 'system';
        $actorType = $actorOverride['type'] ?? ($user !== null ? 'user' : 'system');
        $actorName = $actorOverride['name'] ?? $user?->name ?? $user?->email;

        $resolvedOrgId = $organizationId ?? \Authkit\Authkit\Facades\Authkit::currentOrganizationId();

        if ($resolvedOrgId === null) {
            throw MissingOrganizationContextException::forAuditLog();
        }

        $request = app()->bound('request') ? app('request') : null;

        return new ResolvedAuditContext(
            actorId: $actorId,
            actorType: $actorType,
            actorName: $actorName,
            organizationId: $resolvedOrgId,
            location: $request?->ip() ?? 'unknown',
            userAgent: $request?->userAgent(),
        );
    }
}
```

**Why resolution happens eagerly, synchronously, before any queue dispatch:** `HasAuditLogs`'s model-event listeners and every `AuditLog::log()` call resolve context **in the calling process** — the same HTTP request or console invocation that mutated the model — never inside the queued job. This is the load-bearing design choice for the "queued-job context loses request auth" problem named in the phase direction: by the time `CreateAuditLogEventJob::handle()` runs on a worker, there is no `Auth::user()` or bound `request()` to read, so the job's constructor carries the already-resolved `ResolvedAuditContext` value object as serialized payload, not a deferred resolution call.

**Implementation steps:**
1. `php artisan make:class AuditLogs/Support/ResolvedAuditContext`
2. `php artisan make:class AuditLogs/Support/AuditActorResolver`
3. `php artisan make:class AuditLogs/Exceptions/MissingOrganizationContextException` (extend `\RuntimeException`; named constructor `forAuditLog()`).
4. Bind `AuditActorResolver` as a singleton in `AuthkitServiceProvider::register()`.

**Feedback loop:**
- Playground: Pest test authenticating a workbench user under the `workos` guard with `Authkit::currentOrganizationId()` faked/bound, vs. no auth at all.
- Parameterized experiment: dataset over `{authenticated + org bound, authenticated + no org bound (throws), unauthenticated (system actor) + org bound, explicit actor override, explicit organizationId override}`.
- Check command: `vendor/bin/pest --filter=AuditLogContext`

### 4.3 `AuditLog` facade — `log()` with Idempotency-Key, queued dispatch

**Laravel mechanism:** dedicated facade (`AuditLog`, per the template's canonical facade list) fronting `AuditLogManager`; queued job for the actual wire call.
**SDK methods wrapped:** `AuditLogs::createEvent(string $organizationId, AuditLogEvent $event, ?RequestOptions $options)`.

**Key design:**

```php
// src/AuditLogManager.php (excerpt — log() only; schema/export/retention in §4.4–4.6)
namespace Authkit\Authkit;

public function log(
    string $action,
    array $targets,
    array $metadata = [],
    ?string $organizationId = null,
    ?array $actor = null,
    ?string $idempotencyKey = null,
): void {
    $resolved = $this->contextResolver->resolve($organizationId, $actor);
    $sanitized = MetadataSanitizer::sanitize($metadata, context: $action);

    CreateAuditLogEventJob::dispatch(
        action: $action,
        occurredAt: \Illuminate\Support\Carbon::now()->toDateTimeImmutable(),
        actor: $resolved,
        targets: $targets,
        metadata: $sanitized,
        idempotencyKey: $idempotencyKey ?? (string) \Illuminate\Support\Str::uuid(),
    );
}
```

```php
// src/AuditLogs/Jobs/CreateAuditLogEventJob.php
namespace Authkit\Authkit\AuditLogs\Jobs;

use WorkOS\Exception\BadRequestException;
use WorkOS\Exception\UnprocessableEntityException;
use WorkOS\RequestOptions;
use WorkOS\Resource\AuditLogEvent;
use WorkOS\Resource\AuditLogEventActor;
use WorkOS\Resource\AuditLogEventContext;
use WorkOS\Resource\AuditLogEventTarget;

class CreateAuditLogEventJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use \Illuminate\Bus\Queueable, \Illuminate\Queue\InteractsWithQueue, \Illuminate\Queue\SerializesModels;

    public function __construct(
        public readonly string $action,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly \Authkit\Authkit\AuditLogs\Support\ResolvedAuditContext $actor,
        public readonly array $targets,
        public readonly array $metadata,
        public readonly string $idempotencyKey,
    ) {
    }

    public function handle(\Authkit\Authkit\WorkosClientManager $clients): void
    {
        $event = new AuditLogEvent(
            action: $this->action,
            occurredAt: $this->occurredAt,
            actor: new AuditLogEventActor(id: $this->actor->actorId, type: $this->actor->actorType, name: $this->actor->actorName),
            targets: array_map(
                fn (array $t) => new AuditLogEventTarget(id: $t['id'], type: $t['type'], name: $t['name'] ?? null, metadata: $t['metadata'] ?? null),
                $this->targets,
            ),
            context: new AuditLogEventContext(location: $this->actor->location, userAgent: $this->actor->userAgent),
            metadata: $this->metadata !== [] ? $this->metadata : null,
        );

        try {
            $clients->client()->auditLogs()->createEvent(
                $this->actor->organizationId,
                $event,
                new RequestOptions(idempotencyKey: $this->idempotencyKey),
            );
        } catch (BadRequestException|UnprocessableEntityException $e) {
            \Illuminate\Support\Facades\Log::error('authkit: audit log event rejected (schema mismatch)', [
                'action' => $this->action,
                'organization_id' => $this->actor->organizationId,
                'status' => $e->statusCode,
                'error' => $e->error,
                'body' => $e->rawBody,
            ]);
            throw new \Authkit\Authkit\AuditLogs\Exceptions\AuditLogSchemaMismatchException($e->getMessage(), previous: $e);
        }
    }
}
```

**Idempotency:** the UUID key is generated once, at `log()` call time, and travels inside the job's constructor payload. A Laravel queue-driven retry of the *same* dispatched job reuses that same key (safe — WorkOS returns the cached first response for 24h). Two independent `AuditLog::log()` calls always get independent UUIDs, so rapid distinct events for the same model+action never collide.

**Implementation steps:**
1. `php artisan make:class AuditLogManager`
2. `php artisan make:class Facades/AuditLog` (facade pattern mirrors the existing `Authkit` facade in `src/Facades/Authkit.php`).
3. `php artisan make:job AuditLogs/Jobs/CreateAuditLogEventJob`
4. `php artisan make:class AuditLogs/Support/MetadataSanitizer`
5. `php artisan make:class AuditLogs/Exceptions/AuditLogSchemaMismatchException` (fallback if `make:exception` isn't available on the pinned Laravel version: `make:class`, extend `\RuntimeException`).
6. Wire `AuditLogManager`'s constructor to `WorkosClientManager` and `AuditActorResolver`; bind singleton in the service provider.

**Feedback loop:**
- Playground: `Queue::fake()` to assert the job is pushed with the right payload; a separate emulate-backed run to execute the job for real against the emulator's audit-logs endpoint.
- Parameterized experiment: dataset over `{single call, retry of the same dispatched job (Idempotency-Key reused), two independent calls (distinct keys), oversized metadata (truncation observed), 4xx from a deliberately mismatched action name}`.
- Check command: `vendor/bin/pest --filter=AuditLogFacade`

### 4.4 Schema passthroughs

**Laravel mechanism:** thin methods on `AuditLogManager`.
**SDK methods wrapped:** `AuditLogs::createSchema(string $actionName, array $targets, ?AuditLogSchemaActorInput $actor, ?array $metadata)`, `AuditLogs::listActions(...)`, `AuditLogs::listActionSchemas(string $actionName, ...)`.

**Key design:** exact passthroughs, same parameter names/order/defaults as the vendored SDK (see method signatures read from `vendor/workos/workos-php/lib/Service/AuditLogs.php`) — no reshaping. `createSchema` is how a consumer app registers the schema an action's future `AuditLog::log()` calls must satisfy (mismatches surface as `AuditLogSchemaMismatchException`, §4.3).

**Implementation steps:**
1. Add `createSchema()`, `listActions()`, `listActionSchemas()` methods to `AuditLogManager`, delegating to `$this->clients->client()->auditLogs()`.

**Feedback loop:**
- Playground: emulate-backed — register a schema, then successfully log a matching event, then log a deliberately non-conforming event and observe the `AuditLogSchemaMismatchException`.
- Parameterized experiment: dataset over `{schema created + matching event passes, schema created + mismatched target type rejected (4xx), unknown action auto-creates schema per SDK docstring}`.
- Check command: `vendor/bin/pest --filter=AuditLogSchema`

### 4.5 Export passthrough (async pending→ready poll)

**Laravel mechanism:** thin `createExport`/`getExport` methods plus a self-requeuing queued job for the poll.
**SDK methods wrapped:** `AuditLogs::createExport(string $organizationId, \DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd, ?array $actions, ?array $actors, ?array $actorNames, ?array $actorIds, ?array $targets)`, `AuditLogs::getExport(string $auditLogExportId)`.

**Key design:**

```php
// src/AuditLogs/Jobs/PollAuditLogExportJob.php
namespace Authkit\Authkit\AuditLogs\Jobs;

use WorkOS\Resource\AuditLogExportState;

class PollAuditLogExportJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use \Illuminate\Bus\Queueable, \Illuminate\Queue\InteractsWithQueue, \Illuminate\Queue\SerializesModels;

    public function __construct(
        public readonly string $exportId,
        public readonly int $attempt = 1,
    ) {
    }

    public function handle(\Authkit\Authkit\WorkosClientManager $clients): void
    {
        $export = $clients->client()->auditLogs()->getExport($this->exportId);

        match ($export->state) {
            AuditLogExportState::Ready => event(new \Authkit\Authkit\AuditLogs\Events\AuditLogExportReady($export)),
            AuditLogExportState::Error, AuditLogExportState::Expired =>
                event(new \Authkit\Authkit\AuditLogs\Events\AuditLogExportFailed($export, reason: $export->state->value)),
            AuditLogExportState::Pending => $this->requeueOrGiveUp($export),
        };
    }

    private function requeueOrGiveUp(\WorkOS\Resource\AuditLogExport $export): void
    {
        $maxAttempts = config('authkit.audit_logs.export_poll_max_attempts', 30);

        if ($this->attempt >= $maxAttempts) {
            event(new \Authkit\Authkit\AuditLogs\Events\AuditLogExportFailed($export, reason: 'timeout'));
            return;
        }

        self::dispatch($this->exportId, $this->attempt + 1)
            ->delay(now()->addSeconds(config('authkit.audit_logs.export_poll_interval_seconds', 10)));
    }
}
```

`createExport()` on `AuditLogManager` dispatches `PollAuditLogExportJob::dispatch($export->id)` immediately after minting the export, so consumers get an `AuditLogExportReady`/`AuditLogExportFailed` Laravel event to listen for instead of hand-rolling their own poll loop. **Document prominently** (docblock on `getExport()` and on `AuditLogExportReady`): the returned `url` expires 10 minutes after mint/refetch — never cache the URL value, only the export ID; re-fetch via `getExport($id)` immediately before use to get a fresh URL.

**Implementation steps:**
1. Add `createExport()`, `getExport()` to `AuditLogManager`.
2. `php artisan make:job AuditLogs/Jobs/PollAuditLogExportJob`
3. `php artisan make:event AuditLogs/Events/AuditLogExportReady`
4. `php artisan make:event AuditLogs/Events/AuditLogExportFailed`
5. Add `audit_logs.export_poll_max_attempts` / `audit_logs.export_poll_interval_seconds` to `config/authkit.php`.

**Feedback loop:**
- Playground: MockHandler queue returning a scripted sequence of `getExport` responses (`pending`, `pending`, `ready`), driving the job's self-requeue with Laravel's `sync` queue connection in tests for determinism.
- Parameterized experiment: dataset over `{immediate ready, N pendings then ready, error state, expired state, max-attempts-exhausted timeout}`.
- Check command: `vendor/bin/pest --filter=AuditLogExport`

### 4.6 Retention passthrough (30 | 365 only)

**Laravel mechanism:** thin methods on `AuditLogManager` with a local guard clause.
**SDK methods wrapped:** `AuditLogs::getOrganizationAuditLogsRetention(string $id)`, `AuditLogs::updateOrganizationAuditLogsRetention(string $id, int $retentionPeriodInDays)`.

**Key design:**

```php
public function getRetention(string $organizationId): \WorkOS\Resource\AuditLogsRetention
{
    return $this->clients->client()->auditLogs()->getOrganizationAuditLogsRetention($organizationId);
}

public function setRetention(string $organizationId, int $days): \WorkOS\Resource\AuditLogsRetention
{
    if (! in_array($days, [30, 365], true)) {
        throw \Authkit\Authkit\AuditLogs\Exceptions\InvalidRetentionPeriodException::forDays($days);
    }

    return $this->clients->client()->auditLogs()->updateOrganizationAuditLogsRetention($organizationId, $days);
}
```

**Skips a dedicated feedback loop** (trivial per the template's carve-out: this is pure local validation + a direct SDK passthrough, no state machine, no async behavior, no ambiguous precedence rules to iterate on). It is still tested — see §7 — folded into `AuditLogsTest.php` rather than getting its own suite/playground.

**Implementation steps:**
1. Add `getRetention()`, `setRetention()` to `AuditLogManager`.
2. `php artisan make:class AuditLogs/Exceptions/InvalidRetentionPeriodException`

### 4.7 `Authkit::portalLink()` + `PortalIntent` enum

**Laravel mechanism:** method on the existing `Authkit` primary-manager singleton; package-owned enum re-exporting the SDK's intent enum so consumer code never imports `WorkOS\Resource\GenerateLinkIntent`.
**SDK methods wrapped:** `AdminPortal::generateLink(string $organization, ?string $returnUrl, ?string $successUrl, ?GenerateLinkIntent $intent, ?array $itContactEmails)`.

**Key design:**

```php
// src/Enums/PortalIntent.php
namespace Authkit\Authkit\Enums;

enum PortalIntent: string
{
    case Sso = 'sso';
    case Dsync = 'dsync';
    case AuditLogs = 'audit_logs';
    case LogStreams = 'log_streams';
    case DomainVerification = 'domain_verification';
    case CertificateRenewal = 'certificate_renewal';
    case BringYourOwnKey = 'bring_your_own_key';

    public function toWorkos(): \WorkOS\Resource\GenerateLinkIntent
    {
        return \WorkOS\Resource\GenerateLinkIntent::from($this->value);
    }
}
```

```php
// src/Authkit.php addition
public function portalLink(
    \Illuminate\Database\Eloquent\Model|string $organization,
    \Authkit\Authkit\Enums\PortalIntent $intent,
    ?string $returnUrl = null,
    ?string $successUrl = null,
    ?array $itContactEmails = null,
): string {
    $organizationId = is_string($organization) ? $organization : (string) $organization->workos_id;

    $response = $this->clients->client()->adminPortal()->generateLink(
        organization: $organizationId,
        returnUrl: $returnUrl,
        successUrl: $successUrl,
        intent: $intent->toWorkos(),
        itContactEmails: $itContactEmails,
    );

    return $response->link;
}
```

Accepts either a raw WorkOS organization ID string or any Eloquent model exposing a `workos_id` attribute (i.e., a model using Phase 3's `HasWorkosOrganization` trait) — duck-typed on the `workos_id` column named explicitly in the contract's Organizations scope row, not on an invented contract interface.

**Implementation steps:**
1. `php artisan make:enum Enums/PortalIntent` (if `make:enum` isn't available on the pinned Laravel version, hand-author — it's a small pure-PHP enum).
2. Add `portalLink()` to `src/Authkit.php`.

**Feedback loop:**
- Playground: emulate-backed (portal-link mint is emulate-covered per the template's Test-Path Selection).
- Parameterized experiment: dataset over the 7 `PortalIntent` cases × `{string org ID input, model instance input}` × `{with/without itContactEmails}`.
- Check command: `vendor/bin/pest --filter=AdminPortal`

### 4.8 Domain-verification event listener → domains projection

**Laravel mechanism:** Laravel event listener, registered against the typed events the Phase 4 sidecar/webhook pipeline dispatches for `organization_domain.verified` / `organization_domain.verification_failed` (the contract explicitly bounds these into the typed-event set, not the generic fallback — see §3).
**SDK methods wrapped:** none directly — consumes already-decoded typed events; the payload shapes mirror `WorkOS\Resource\OrganizationDomainVerified` / `OrganizationDomainVerificationFailed` (which wrap `OrganizationDomainVerifiedData` / `OrganizationDomainVerificationFailedData` → `OrganizationDomainVerificationFailedDataOrganizationDomain`).

**Key design:**

```php
// src/Listeners/UpdateOrganizationDomainVerificationState.php
namespace Authkit\Authkit\Listeners;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class UpdateOrganizationDomainVerificationState
{
    public function handleVerified(/* Authkit\Authkit\Events\Workos\OrganizationDomainVerified */ $event): void
    {
        $this->applyState($event->payload->id, 'verified', ['verification_prefix' => null, 'verification_token' => null]);
    }

    public function handleVerificationFailed(/* Authkit\Authkit\Events\Workos\OrganizationDomainVerificationFailed */ $event): void
    {
        $this->applyState($event->payload->organizationDomain->id, 'failed', []);
    }

    private function applyState(string $workosDomainId, string $state, array $extra): void
    {
        DB::transaction(function () use ($workosDomainId, $state, $extra) {
            $row = /* the Phase 3 domains-projection model */ ::query()
                ->where('workos_id', $workosDomainId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                Log::warning('authkit: domain-verification event for unknown domain projection row', [
                    'workos_id' => $workosDomainId,
                    'state' => $state,
                ]);
                return;
            }

            $row->forceFill(['state' => $state, ...$extra])->save();
        });
    }
}
```

**Implementation steps:**
1. `php artisan make:listener Listeners/UpdateOrganizationDomainVerificationState --event=OrganizationDomainVerified` (generator gives one handler method; add the second `handleVerificationFailed` method by hand and register both explicitly rather than relying on Laravel's single-method listener auto-invocation).
2. Confirm the exact typed event class names and domains-projection model/columns against the (by-then-written) Phase 3 and Phase 4 specs — see Open Items #2 and #3. Adjust the `use` imports and `::query()` model reference accordingly; the row-update logic above does not change.
3. Register both handlers in `AuthkitServiceProvider::boot()` (§6) — **before** the console-only early return, since the events-sidecar worker (`authkit:work`) runs in console context and must have these listeners bound too.

**Feedback loop:**
- Playground: dispatch a real (not faked) instance of the typed event against a seeded domains-projection row, assert the row's `state` column.
- Parameterized experiment: dataset over `{pending→verified, pending→failed (domain_verification_period_expired), pending→failed (domain_verified_by_other_organization), event for a workos_id with no local row (warn + no-op), event arriving concurrently with a local delete (lockForUpdate, no-op on vanished row)}`.
- Check command: `vendor/bin/pest --filter=OrganizationDomainVerification`

## 5. File Changes

### New files

| Path | Purpose | Traces to scope item |
|---|---|---|
| `src/AuditLogManager.php` | Facade-fronted manager: `log()`, `createSchema()`, `listActions()`, `listActionSchemas()`, `createExport()`, `getExport()`, `getRetention()`, `setRetention()` | Audit Logs — facade, schema/export/retention passthroughs |
| `src/Facades/AuditLog.php` | `AuditLog` facade (canonical per template's Shared Conventions) | Audit Logs — "manual AuditLog facade" |
| `src/Concerns/HasAuditLogs.php` | Lifecycle-action trait | Audit Logs — "HasAuditLogs trait" |
| `src/Attributes/AuditActions.php` | Per-model action-name override attribute | Audit Logs — "per-action opt-in via attribute/method" |
| `src/Enums/PortalIntent.php` | Re-exported 7-case portal intent enum | Admin Portal — "portal-link facade covering all 7 intents" |
| `src/AuditLogs/Support/ResolvedAuditContext.php` | Eager-resolved actor/org context DTO | Audit Logs — "auto actor/org context" |
| `src/AuditLogs/Support/AuditActorResolver.php` | Actor/org resolution service | Audit Logs — "auto actor/org context" |
| `src/AuditLogs/Support/MetadataSanitizer.php` | Metadata cap enforcement (≤50 keys, ≤500 chars/value) | Audit Logs — "metadata method ... capped to WorkOS limits" |
| `src/AuditLogs/Jobs/CreateAuditLogEventJob.php` | Queued `createEvent` call with Idempotency-Key | Audit Logs — "manual AuditLog facade" |
| `src/AuditLogs/Jobs/PollAuditLogExportJob.php` | Self-requeuing export-state poll | Audit Logs — "export ... passthrough" |
| `src/AuditLogs/Events/AuditLogExportReady.php` | Laravel event on export ready | Audit Logs — "export ... passthrough" |
| `src/AuditLogs/Events/AuditLogExportFailed.php` | Laravel event on export error/expired/timeout | Audit Logs — "export ... passthrough" |
| `src/AuditLogs/Exceptions/AuditLogSchemaMismatchException.php` | Named 4xx schema-mismatch exception | Audit Logs — failure-mode requirement |
| `src/AuditLogs/Exceptions/InvalidRetentionPeriodException.php` | Named local-validation exception | Audit Logs — "retention ... passthrough" |
| `src/AuditLogs/Exceptions/MissingOrganizationContextException.php` | Named context-resolution failure | Audit Logs — "auto actor/org context" |
| `src/Listeners/UpdateOrganizationDomainVerificationState.php` | Verification-outcome → domains-projection sync | Admin Portal — "domain-verification events update the domains projection" |
| `workbench/app/Models/Post.php` | Consumer-example model using `HasAuditLogs` (default convention, `SoftDeletes`) | Audit Logs — workbench example, zero-SDK-reference target |
| `workbench/database/migrations/{timestamp}_create_posts_table.php` | Backing table for the example model (app-owned table, not a WorkOS projection) | Audit Logs — workbench example |
| `workbench/database/factories/PostFactory.php` | Factory for the example model | Audit Logs — workbench example |
| `tests/Feature/AuditLogsTest.php` | emulate-backed: trait lifecycle dispatch, `log()`, schema, retention | Audit Logs |
| `tests/Feature/AuditLogsExportTest.php` | MockHandler-backed: export create + poll state machine | Audit Logs (export gap in emulate) |
| `tests/Feature/AdminPortalTest.php` | emulate-backed: `portalLink()` across all 7 intents | Admin Portal |
| `tests/Feature/OrganizationDomainVerificationTest.php` | Event/listener test, no wire call: domains-projection updates | Admin Portal — domain-verification wiring |
| `tests/Unit/MetadataSanitizerTest.php` | Truncation-cap unit coverage | Audit Logs |
| `tests/Unit/AuditLogActionsResolutionTest.php` | Property vs. attribute vs. default precedence, pure-PHP | Audit Logs |

### Modified files

| Path | Change | Traces to scope item |
|---|---|---|
| `src/Authkit.php` | Add `portalLink()` method (§4.7) | Admin Portal |
| `src/AuthkitServiceProvider.php` | Register `AuditLogManager`/`AuditActorResolver` singletons; register the two domain-verification listener bindings before the console-only early return (§6) | Both scope rows |
| `config/authkit.php` | Add `audit_logs.export_poll_max_attempts` / `audit_logs.export_poll_interval_seconds` | Audit Logs — export passthrough |
| `composer.json` | Add `"AuditLog": "Authkit\\Authkit\\Facades\\AuditLog"` to `extra.laravel.aliases` | Audit Logs — facade discovery |

**No `database/migrations/` changes.** Audit Logs introduces zero local WorkOS-shaped state — every audit event is written straight through to WorkOS with no local shadow, per the contract's canonical-state doctrine. The domains-projection row touched by §4.8's listener is Phase 3's existing table; this phase assumes (Open Item #3) it already carries a `state` column, since the projection's whole purpose is reflecting verification status.

## 6. Service Provider Registration Diff

```php
// register()
$this->app->singleton(\Authkit\Authkit\AuditLogManager::class);
$this->app->singleton(\Authkit\Authkit\AuditLogs\Support\AuditActorResolver::class);

// boot() — added BEFORE the `if (! $this->app->runningInConsole()) { return; }` guard,
// because the events-sidecar worker (authkit:work, console-only) must have these bound too.
\Illuminate\Support\Facades\Event::listen(
    /* Authkit\Authkit\Events\Workos\OrganizationDomainVerified */ '\Authkit\Authkit\Events\Workos\OrganizationDomainVerified',
    [\Authkit\Authkit\Listeners\UpdateOrganizationDomainVerificationState::class, 'handleVerified'],
);
\Illuminate\Support\Facades\Event::listen(
    /* Authkit\Authkit\Events\Workos\OrganizationDomainVerificationFailed */ '\Authkit\Authkit\Events\Workos\OrganizationDomainVerificationFailed',
    [\Authkit\Authkit\Listeners\UpdateOrganizationDomainVerificationState::class, 'handleVerificationFailed'],
);
```

The listener registration's event-class string literals are placeholders for whatever exact class names Phase 4 lands on (Open Item #2) — swap in the real `::class` constants at implementation time.

## 7. Testing Requirements

| Suite | Test path | Key cases | Seed data |
|---|---|---|---|
| `tests/Feature/AuditLogsTest.php` | emulate | `HasAuditLogs` create/update/soft-delete/force-delete/restore dispatch (via `Queue::fake()`); `AuditLog::log()` end-to-end against the emulator; `createSchema`/`listActions`/`listActionSchemas`; `getRetention`/`setRetention` incl. `InvalidRetentionPeriodException` for e.g. `90`; oversized metadata truncation; missing-org-context throw | Emulate-seeded org (`workos-emulate.config.yaml`); workbench `Post` rows |
| `tests/Feature/AuditLogsExportTest.php` | MockHandler | `createExport` mint; `PollAuditLogExportJob` sequences: immediate ready, N-pendings-then-ready, error, expired, max-attempts timeout; `AuditLogExportReady`/`AuditLogExportFailed` dispatched with correct payload | Scripted MockHandler response queue per case |
| `tests/Feature/AdminPortalTest.php` | emulate | `Authkit::portalLink()` for all 7 `PortalIntent` cases; string-ID input vs. model-instance input; with/without `itContactEmails` | Emulate-seeded org |
| `tests/Feature/OrganizationDomainVerificationTest.php` | N/A (pure event/listener — no WorkOS wire call; dispatch the typed event directly and assert DB state) | verified → projection `state=verified` + token fields cleared; verification_failed (both reason codes) → `state=failed`; event for unknown `workos_id` → warning logged, no row created, no crash; concurrent-delete race → no-op on vanished row | Seeded `organization_domains` (or whatever Phase 3 names it) row |
| `tests/Unit/MetadataSanitizerTest.php` | N/A | 51 keys → 50 kept; 501-char value → truncated to 500; well-formed input passes through unchanged | — |
| `tests/Unit/AuditLogActionsResolutionTest.php` | N/A | default slug naming; `$auditActions` property full override; `$auditActions` partial override; `#[AuditActions]` attribute override; property wins over attribute when both present | — |

Per the template's test-path rule ("never mix paths within one test; name the path in the file's top comment"), each file above states its path in a top-of-file comment.

## 8. Failure Modes

| # | Component | Named failure | Trigger | Mitigation |
|---|---|---|---|---|
| 1 | Context resolver | Automatic (trait-driven) audit calls fail outside an established org context | A queued job or console command mutates an audited model with no bound "current organization" (e.g., a scheduled cleanup job touching rows across many orgs) | `AuditActorResolver::resolve()` throws `MissingOrganizationContextException` naming the model/action rather than sending `organization_id: null` (WorkOS would reject it anyway) or silently guessing; message tells the developer to bind org context first or call `AuditLog::log()` manually with an explicit `organizationId` |
| 2 | Context resolver | Actor silently attributed to `'system'` when a detached job represents a specific user's action | A queued job processes a batch that was actually initiated by a specific admin, but runs with no bound auth session | Documented, intentional default — not engineered around; pass an explicit `actor` array to `AuditLog::log()` when a detached context should still be attributed to a real person |
| 3 | `CreateAuditLogEventJob` | Schema mismatch (4xx) on `createEvent` | Event's `action`/target `type`/metadata key doesn't match the Dashboard-configured schema (via `createSchema`, §4.4) | Catch `BadRequestException`/`UnprocessableEntityException`, `Log::error()` with action/org/status/body, rethrow as `AuditLogSchemaMismatchException` — a named, greppable class instead of a generic failed-job entry |
| 4 | `MetadataSanitizer` | Metadata exceeds WorkOS limits (>50 keys / >500 chars per value) | Developer's `auditMetadata()` returns an oversized array | Truncate locally to 50 keys / 500 chars *before* the request leaves the process, `Log::warning()` naming the model/action so the truncation isn't silent — avoids losing the whole audit record to a 4xx |
| 5 | `AuditLog::log()` | Idempotency-Key collision across genuinely distinct events | If keys were derived deterministically from event content instead of freshly generated | Always `Str::uuid()` at call time; reused only across queue-driven retries of the *same* dispatched job (safe), never across independent `log()` calls |
| 6 | Export passthrough | Export URL expires 10 minutes after mint/refetch | Consumer caches the `url` value and uses it later (e.g., queued email/upload) | Document on `getExport()`/`AuditLogExportReady`: cache only the export ID, re-fetch immediately before use to regenerate a fresh URL |
| 7 | `PollAuditLogExportJob` | Export poll requeues forever on a stuck `pending` state | A stuck or slow export never transitions | Bounded `export_poll_max_attempts` (config, default 30) with fixed backoff; on exhaustion, dispatch `AuditLogExportFailed(reason: 'timeout')` and stop self-requeueing |
| 8 | Domain-verification listener | Event for a domain not (or no longer) in the local projection | Out-of-order delivery, replay, or the local row was deleted while WorkOS still verifies the domain | `Log::warning()` and return — no crash, no orphan row created; reconciled later by Phase 4's created/updated listeners or the Stretch-tier State API reconciliation |
| 9 | Domain-verification listener | Race with a concurrent local domain deletion | App code deletes the local domain row at the same moment a `verified`/`verification_failed` event for it arrives | `lockForUpdate()` inside a transaction; a vanished row after the lookup is treated identically to failure #8 (no-op), not an error |
| 10 | All wire-touching components | WorkOS API unreachable / 5xx after SDK retries exhaust | Outage or network partition mid-call | `createEvent` is queue-retryable and idempotency-key-safe; `portalLink()`/export/retention passthroughs propagate the `WorkOSException` synchronously to the caller (these are direct, user-initiated calls with no queue to hide behind — the calling controller/command decides retry policy) |
| 11 | Retention passthrough | Retention set to a value other than 30 or 365 | Caller passes e.g. `90` | `setRetention()` validates locally and throws `InvalidRetentionPeriodException` before any network call — no wasted round-trip to a server-side 4xx |
| 12 | Context resolver | Stale `org_id` claim between session refreshes misattributes an audit event | User switched organizations in another tab/request; this request's token hasn't refreshed yet | Acknowledged per the shared bounded-staleness doctrine, not engineered around — audit attribution inherits the same staleness bound as every other claims-based read in the package |
| 13 | All emulate-backed calls (`createEvent`, `createSchema`, `listActions`/`listActionSchemas`, `getRetention`/`setRetention`, `portalLink`) | No known emulate-vs-production behavioral drift for this phase's calls | Moving from emulate-backed CI (§7) to a real WorkOS environment | Per the context brief's per-service emulate coverage notes, Audit Logs is flagged PARTIAL only for the missing export endpoint (already routed to MockHandler, §7) and Admin Portal is flagged "link mint only" — exactly this phase's need. Neither note names a behavioral mismatch for create-event, schema, retention, or portal-link mint the way the brief calls out auth's refresh-tokens-always-rotate quirk (Phase 2's concern, not this phase's). Recorded here as an explicit "no known drift" rather than left silent: if a future emulate version surfaces a mismatch in schema-validation strictness or retention-day enforcement between emulate and production, log it as a new Open Item rather than assuming today's emulate-backed tests generalize |

## 9. Deviations

None that contradict the template's binding conventions. Organizational additions, named explicitly so implementers don't miscategorize them:

1. **`AuditLogs\*` internal sub-namespace** (`AuditLogs\Jobs`, `AuditLogs\Support`, `AuditLogs\Events`, `AuditLogs\Exceptions`) — not prescribed by the template's Shared Conventions table, which only names top-level `Facades` and `Events\Workos\*`. Added for organization, following the same "internals get their own sub-namespace, separate from the public facade surface" precedent the template already sets for `Events\Workos\*`.
2. **`AuditLogExportReady`/`AuditLogExportFailed` are package-internal lifecycle events, not WorkOS-typed events** — they live under `Authkit\Authkit\AuditLogs\Events\*`, *not* `Authkit\Authkit\Events\Workos\*`, because they're synthesized by this phase's own polling logic rather than decoded from a WorkOS event payload. Flagged explicitly so they aren't confused with the bounded typed-event set from Phase 4.
3. **`listActions`/`listActionSchemas`/`createSchema`/`createExport`/`getExport`/`getRetention` return SDK resource DTOs unwrapped** (see the design note opening §4) — a deliberate usable-core-depth choice, not an oversight; `portalLink()` is the one method in this phase that gets the fuller Laravel-native wrap (bare `string`), matching the phase direction's own language distinguishing it from the "passthrough" components.

## 10. Validation Commands

```bash
composer analyse                                   # PHPStan (larastan)
composer lint:check                                # Pint check-only
composer test:types                                # Pest type coverage --min=100
vendor/bin/pest --filter=HasAuditLogsTrait          # §4.1
vendor/bin/pest --filter=AuditLogContext            # §4.2
vendor/bin/pest --filter=AuditLogFacade             # §4.3
vendor/bin/pest --filter=AuditLogSchema             # §4.4
vendor/bin/pest --filter=AuditLogExport             # §4.5
vendor/bin/pest --filter=AdminPortal                # §4.7
vendor/bin/pest --filter=OrganizationDomainVerification  # §4.8
composer test                                       # full chain — must be green before commit
```

## Open Items (cross-phase assumptions — confirm once the named phase's spec exists)

1. **Current-organization accessor.** Assumed `Authkit::currentOrganizationId(): ?string` on the primary `Authkit` manager, exposing whatever Phase 3 resolves from claims/middleware. If Phase 3 names or shapes this differently, update `AuditActorResolver::resolve()` (§4.2) accordingly — the rest of the design (eager resolution, exception on null) is unaffected.
2. **Typed domain-verification event class names.** Assumed `Authkit\Authkit\Events\Workos\OrganizationDomainVerified` / `OrganizationDomainVerificationFailed`, mirroring the vendored SDK's `WorkOS\Resource\OrganizationDomainVerified`/`OrganizationDomainVerificationFailed` resource names 1:1 under the template's `Events\Workos\*` namespace convention. Confirm against the Phase 4 spec once written; update the `Event::listen()` calls in §6 and the listener's type hints in §4.8.
3. **Domains-projection model/table/columns.** Assumed a Phase 3-owned model mapping to a table with at least `workos_id`, `domain`, `state`, `verification_prefix`, `verification_token` columns (mirroring `WorkOS\Resource\OrganizationDomain`'s fields). Confirm exact model class and column names against the Phase 3 spec; update §4.8's `::query()` reference.
4. **`WorkosClientManager`'s accessor for a raw SDK client.** Assumed `WorkosClientManager::client(): \WorkOS\WorkOS` (per the context brief's "our `WorkosClientManager` reads `config('authkit.base_url')`" and Phase 1's role as the client-binding phase). Confirm the exact method name against the Phase 1 spec; every `$clients->client()->...` call site in this phase (§4.3, §4.5, §4.6, §4.7) would need the same one-line rename if wrong.
