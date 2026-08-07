# Phase 12 — Depth Extensions (Full Tier)

Follow `spec-template-feature-area.md`; inputs below.

## Phase Header

- **Phase**: 12 of 13
- **Title**: Depth Extensions (Full Tier)
- **Estimated effort**: **L** (five distinct sub-features, one cross-cutting cache subsystem, six new Pest suites, two modified prior-phase files)
- **Risk**: medium (per contract)
- **Prereq phases**: Phase 5 (Authorization: RBAC + FGA), Phase 3 (Organizations & Org Context), Phase 4 (Events Pipeline & Webhooks)
- **Blocking**: No (contract: `"blocking": false`) — Phase 13 depends on this phase completing, but nothing downstream of Phase 13 depends on Phase 12 alone.

### Why this phase exists

The contract's stakeholder tier-selection decision moved v1's target from MVP to Full: "MVP's 16 areas plus the 5 depth extensions ... folded in as a dedicated Depth Extensions phase." This is that phase. Every sub-feature here is an **extension of an area that already shipped at usable-core depth in an earlier phase** — nothing here introduces a new WorkOS product area or new local state. That framing matters for scope discipline: if an implementation step in this phase looks like it needs a new Eloquent table, migration, or a brand-new local projection, stop — re-read the contract's projection-boundary decision, because Depth Extensions is not the phase where that boundary should move.

## Standalone-Implementability: Assumed Interfaces from Prior Phases

This spec is written to be actioned by an agent that has **not** read Phases 3, 4, or 5's own spec files (they may not exist yet at the time this phase is implemented, or may have drifted). Everything below that this phase depends on is stated explicitly, with its assumed path/signature, so the component descriptions later in this document are self-contained. **At implementation time, open the actual Phase 3/4/5 code first and reconcile names** — if a class or method below doesn't match what actually shipped, adapt the call sites in this phase to the real name; the design intent (what wraps what, what gets cached, what gets invalidated) does not change.

| Assumed symbol | Assumed path | Assumed shape | Source phase |
|---|---|---|---|
| `Authkit\Authkit\WorkosClientManager` | `src/WorkosClientManager.php` | `client(): \WorkOS\WorkOS` — config-driven singleton, base-URL override for emulate, injectable Guzzle handler | Phase 1 |
| `Authkit\Authkit\Authkit` | `src/Authkit.php` | Primary facade-backing class; existing accessor pattern (`connect()`, `pipes()`, `portalLink()`, ...) that this phase extends | Phase 1 (skeleton exists today) |
| `Authkit\Authkit\Concerns\HasWorkosResource` | `src/Concerns/HasWorkosResource.php` | Trait with `workosResourceExternalId(): string`, `workosResourceTypeSlug(): string`, and a resource-sync method (assumed name `syncAsWorkosResource(): void`) called from a model observer, creating/updating the model's `AuthorizationResource` in WorkOS keyed by external ID | Phase 5 |
| `Authkit\Authkit\Authorization\FgaManager` | `src/Authorization/FgaManager.php` | Class behind `Authkit::fga()`; public `check(string $organizationMembershipId, string $permissionSlug, ResourceTarget $target): bool` wrapping `Authorization::check()` directly (no cache in Phase 5) | Phase 5 |
| `Authkit\Authkit\Authorization\ResourceTarget` | `src/Authorization/ResourceTarget.php` | Value object with named constructors `byId(string $id)` / `byExternalId(string $externalId, string $typeSlug)`, used to avoid consumer code touching `\WorkOS\Service\ResourceTargetById\|ByExternalId` directly | Phase 5 |
| `Authkit\Authkit\Events\Workos\OrganizationMembershipCreated` / `Updated` / `Deleted` | `src/Events/Workos/` | Typed Laravel events dispatched by the events sidecar for membership WorkOS event types, since memberships are a declared projection | Phase 4 |
| `Authkit\Authkit\Events\Workos\GenericWorkosEvent` | `src/Events/Workos/GenericWorkosEvent.php` | Fallback event for every WorkOS event type not in the bounded typed set; carries `public readonly string $type` and `public readonly array $payload` | Phase 4 |
| `Authkit\Authkit\AuthkitServiceProvider` | `src/AuthkitServiceProvider.php` | Exists today (skeleton); by Phase 12 it merges `config/authkit.php` (renamed from `authkit-laravel.php` in Phase 1) | Phase 1 |

If Phase 5 did **not** ship a `ResourceTarget` value object (e.g., it inlined the SDK union type behind `check()`'s own parameters instead), the components in this phase that need parent-resource targeting (Component 5, Component 6) must introduce the equivalent value object themselves — see Component 5's Deviations note.

## Scope Rows Implemented (verbatim from contract, Full tier)

| # | Contract scope row | Reason (from contract) |
|---|---|---|
| 1 | Invitations flows surfaced as facade/form-request helpers | Depth extension — starter kit will want it, not needed to make WorkOS-native the paved path |
| 2 | JWT template + CORS origin management passthroughs | Dashboard-adjacent management APIs, rarely touched at runtime |
| 3 | Groups API surface (org groups CRUD, group role assignments) | Depth beyond usable-core RBAC |
| 4 | FGA resource-graph conveniences (parent hierarchies via nested traits, resource discovery helpers) | Beyond usable-core FGA checks |
| 5 | Opt-in FGA check caching with events-driven invalidation (role/membership/resource events bust the cache) | Moved from MVP by the over-engineering review: no latency requirement stated, and stale cache = stale permission decision without invalidation wiring |

Binding phase-specific direction (from the task) additionally pins down, per row: Invitations gets send/list/get/resend/revoke + an accept-URL helper + a workbench example (no form-request classes — see Deviations, this row's contract wording says "facade/form-request helpers" but the SDK/UX shape here is pure management API, not an HTTP form submission, so no `FormRequest` subclass is created); JWT templates get `listJWTTemplate`/`updateJWTTemplate` with a loud 4KB-ceiling warning; CORS gets list/create (delete is not an SDK v9.1.0 capability — see Deviations); Groups gets org groups CRUD, group role assignments, and `listOrganizationMembershipGroups`, tested against `MockHandler` because `emulate` has no group endpoints; FGA gets `HasWorkosResource` parent-hierarchy config via `ParentResourceByExternalId`, `listResourcesForMembership` + `listMembershipsForResource` helpers, and the opt-in check cache with `authkit.fga.cache.{enabled,ttl,store}` config, disabled by default, invalidated by Phase 4 events.

## Decisions Considered and Rejected (carried from contract)

| Decision | Rejected alternative | Reason | Why it binds this phase |
|---|---|---|---|
| v1 targets the Full tier: MVP's 16 areas plus the 5 depth extensions, folded into a dedicated Depth Extensions phase | MVP-only v1 with depth extensions deferred post-starter-kit | Stakeholder tier selection at contract approval | This is literally this phase's charter — it exists only because of this decision |
| FGA ships without caching in MVP — direct Check API per check; opt-in caching with events-driven invalidation is Full tier | Default per-check cache in MVP | No stated latency requirement, and a stale cache entry is a stale permission decision; caching only earns its keep with invalidation wiring | Component 7 (FGA check cache) is the invalidation wiring this decision explicitly deferred to here — it must not exist without it |
| RBAC reads come from JWT claims (zero HTTP per check); FGA is the explicit escalation path via the Check API | Sync WorkOS roles/permissions into local spatie-style tables | Claims already ride the access token so checks are free; local tables duplicate canonical WorkOS state and drift | Explains why only FGA gets a cache in this phase and RBAC does not — RBAC has no per-request HTTP cost to amortize |
| WorkOS is canonical; local state = declared projections only (user link, org model, org domains, memberships) + events cursor | No local state / read-through API calls per request | Laravel's ecosystem assumes Eloquent models; WorkOS best practice is local state kept fresh by events | No component in this phase may add a table. Groups, Invitations, CORS, and JWT templates are pure passthroughs; the FGA cache is a *cache*, not a projection — it holds derived boolean decisions with a TTL and a bust mechanism, not authoritative WorkOS state, and it lives behind an opt-in config flag defaulted off |
| Typed sidecar events are bounded to types feeding the declared projections + audit/domain-verification; everything else dispatches a generic `WorkosEvent` | A typed Laravel event class per WorkOS event type | Unbounded typed mapping would cover out-of-scope products | Membership events are typed (memberships are a projection); role-assignment and authorization-resource events are **not** — they dispatch through `GenericWorkosEvent`. Component 7's invalidation listener must listen to both channels, not assume new typed classes exist for role/resource events |
| Events API sidecar is the primary sync transport; webhooks are optional low-latency triggers sharing the same Laravel event objects | Webhooks-primary sync | Events API is the durable, cursor-backed source | The FGA cache invalidation listener attaches to the *Laravel event objects* the sidecar (and, redundantly, webhooks) dispatch — it does not poll or call WorkOS itself |
| Credentials read from config only; `env()` never read outside config files | Runtime `env()` reads like the SDK's own fallback does | `config:cache` empties env at runtime | The new `authkit.fga.cache.*` config keys read `env()` inside `config/authkit.php` (the one place it's allowed) and are consumed everywhere else via `config()` |
| Custom `workos` guard with the AuthKit sealed session cookie as canonical auth state | Exchange code then hydrate Laravel's standard session guard | WorkOS must remain the session source of truth | Context for the JWT-template warning (Component 2): the sealed cookie's size is bounded at 4KB, and template edits change what rides inside it |
| Phase 1 ends with an empirical AuthKit token audit confirming canonical `iss`/`aud` and default claim presence | Assume the SDK's TODO values and default-populated claims | Hidden-dependency blocker: claims silently backing zero-HTTP RBAC and claim-first flags | Same context as above — the audited claim set is exactly what a JWT template edit can grow or shrink; the warning in Component 2 exists because that audit's assumptions are load-bearing for Phases 2/5/7 |
| Stay on Pest 4 with PHP ^8.3 floor | Pest 5 (requires PHP 8.4+) | PHP 8.3 supported until Dec 2027 | All six new test suites in this phase are Pest 4 |
| Truth bar: emulate-backed Pest tests in CI, MockHandler fakes only where emulate lacks coverage | SDK fakes only | Wire fidelity where possible | Drives the emulate-vs-MockHandler split in Testing Requirements below |

Decisions from the contract log **not** carried here because they don't bind this phase's design: the routes-as-thin-wrappers-over-form-requests decision (this phase adds no HTTP auth surface); `php artisan dev` wiring (no new long-running process); Directory Sync exclusion; widgets exclusion; the express-run/git-anchor process decision. They were reviewed and are genuinely not load-bearing here.

## Feedback Strategy

**Inner-loop command** (seconds, scoped): `vendor/bin/pest --filter={AreaSuite}` per area — six distinct filters below, each running against an in-process `MockHandler` or a locally-running `emulate` with zero real network calls, so each completes in low single-digit seconds.

**Phase-wide loop** (still fast, no network): `vendor/bin/pest --group=depth-extensions` runs all six suites together.

| Area | Filter | Path |
|---|---|---|
| Invitations | `--filter=Invitations` | emulate |
| JWT Template | `--filter=JwtTemplate` | emulate |
| CORS Origins | `--filter=CorsOrigins` | MockHandler |
| Groups | `--filter=Groups` | MockHandler |
| FGA resource graph | `--filter=FgaResourceGraph` | MockHandler |
| FGA check cache | `--filter=FgaCache` | MockHandler (cache assertions use Laravel's `array` store, no external cache backend needed) |

**Why MockHandler for CORS and FGA, emulate for Invitations/JWT**: `emulate`'s documented coverage lists invitations and JWT templates as SOLID; it lists no CORS-origin coverage at all (unconfirmed — treated as absent, see Open Items) and its seed schema (`workos-emulate.config.yaml`) has no `resources`/`resourceTypes` key, so FGA resource-graph and cache behavior cannot be exercised against it. Groups is explicit in the phase direction: "MockHandler, emulate lacks group endpoints" — confirmed by emulate's own coverage notes ("authorization (no group endpoints)").

## Components

### Component 1 — Invitations facade

**Laravel mechanism**: facade accessor `Authkit::invitations()` returning a plain manager class (matches the existing pattern for `Authkit::connect()` / `Authkit::pipes()` — no new dedicated `Facade` subclass, per the shared convention that only `Authkit`, `Vault`, and `AuditLog` get their own facades).

**SDK methods wrapped** (`\WorkOS\Service\UserManagement`, exact v9.1.0 signatures):
- `listInvitations(?before, ?after, ?limit, PaginationOrder $order, ?organizationId, ?email, ?RequestOptions $options): PaginatedResponse<UserInvite>`
- `sendInvitation(string $email, ?organizationId, ?roleSlug, ?expiresInDays, ?inviterUserId, ?CreateUserInviteOptionsLocale $locale, ?RequestOptions $options): UserInvite`
- `findInvitationByToken(string $token, ?RequestOptions $options): UserInvite`
- `getInvitation(string $id, ?RequestOptions $options): UserInvite`
- `acceptInvitation(string $id, ?RequestOptions $options): Invitation`
- `resendInvitation(string $id, ?CreateUserInviteOptionsLocale $locale, ?RequestOptions $options): UserInvite`
- `revokeInvitation(string $id, ?RequestOptions $options): Invitation`

**Key design**:

```php
namespace Authkit\Authkit\Invitations;

use Authkit\Authkit\WorkosClientManager;
use WorkOS\PaginatedResponse;
use WorkOS\RequestOptions;
use WorkOS\Resource\CreateUserInviteOptionsLocale;
use WorkOS\Resource\Invitation;
use WorkOS\Resource\UserInvite;

final class InvitationManager
{
    public function __construct(private readonly WorkosClientManager $clients) {}

    public function list(
        ?string $organizationId = null,
        ?string $email = null,
        ?string $before = null,
        ?string $after = null,
        ?int $limit = null,
    ): PaginatedResponse {
        return $this->clients->client()->userManagement()->listInvitations(
            before: $before,
            after: $after,
            limit: $limit,
            organizationId: $organizationId,
            email: $email,
        );
    }

    public function send(
        string $email,
        ?string $organizationId = null,
        ?string $roleSlug = null,
        ?int $expiresInDays = null,
        ?string $inviterUserId = null,
        ?CreateUserInviteOptionsLocale $locale = null,
        ?string $idempotencyKey = null,
    ): UserInvite {
        return $this->clients->client()->userManagement()->sendInvitation(
            email: $email,
            organizationId: $organizationId,
            roleSlug: $roleSlug,
            expiresInDays: $expiresInDays,
            inviterUserId: $inviterUserId,
            locale: $locale,
            options: $idempotencyKey !== null ? new RequestOptions(idempotencyKey: $idempotencyKey) : null,
        );
    }

    public function get(string $id): UserInvite
    {
        return $this->clients->client()->userManagement()->getInvitation($id);
    }

    public function findByToken(string $token): UserInvite
    {
        return $this->clients->client()->userManagement()->findInvitationByToken($token);
    }

    public function resend(string $id, ?CreateUserInviteOptionsLocale $locale = null): UserInvite
    {
        return $this->clients->client()->userManagement()->resendInvitation($id, $locale);
    }

    public function revoke(string $id): Invitation
    {
        return $this->clients->client()->userManagement()->revokeInvitation($id);
    }

    public function accept(string $id): Invitation
    {
        return $this->clients->client()->userManagement()->acceptInvitation($id);
    }

    /** Trivial — the SDK already returns the accept URL on the resource; this exists so callers never build the URL by hand. */
    public function acceptUrl(Invitation|UserInvite $invitation): string
    {
        return $invitation->acceptInvitationUrl;
    }
}
```

**Note on `accept()`**: AuthKit's hosted UI normally accepts invitations itself during the standard login/callback flow (the invitation token rides the authorization URL). `accept()` exists for apps that build their own custom invitation-acceptance UI ahead of redirecting to AuthKit — same "parity with custom-controller apps" spirit as the Phase 2 form-request decision, just without a `FormRequest` class because there's no form submission shape here, only a management-API call.

**Implementation steps**:
1. No generator applies — this is a plain PHP class with no Eloquent/migration/notification shape. Hand-author `src/Invitations/InvitationManager.php` following the skeleton's existing flat-namespace style.
2. Add `invitations(): InvitationManager` to `src/Authkit.php`.
3. Write `tests/Feature/InvitationsTest.php` against `emulate` (seed a pending invitation via `workos-emulate.config.yaml`'s `invitations` seed key or via `send()` itself in test setup).
4. Add the workbench demo route (see File Changes).

**Feedback loop**:
- Playground: `vendor/bin/pest --filter=Invitations` (emulate) + `composer serve` hitting the workbench demo route.
- Parameterized experiment: a Pest dataset varying invitation state transitions — `send → get (pending) → resend → revoke → get (revoked)` and `send → get (pending) → accept → get (accepted)` — asserting `UserInviteState` at each step.
- Check: same filter command; green means every transition + the accept-URL helper round-trip.

---

### Component 2 — JWT template passthrough with loud warning

**Laravel mechanism**: `Authkit::jwtTemplate()` accessor.

**SDK methods wrapped**: `UserManagement::listJWTTemplate(?RequestOptions): JWTTemplateResponse`, `UserManagement::updateJWTTemplate(string $content, ?RequestOptions): JWTTemplateResponse`.

**Key design** — the loud warning is the entire point of this component; the wrap itself is two one-line calls:

```php
namespace Authkit\Authkit\JwtTemplates;

use Authkit\Authkit\Events\JwtTemplateUpdated;
use Authkit\Authkit\WorkosClientManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use WorkOS\Resource\JWTTemplateResponse;

final class JwtTemplateManager
{
    public function __construct(private readonly WorkosClientManager $clients) {}

    public function get(): JWTTemplateResponse
    {
        return $this->clients->client()->userManagement()->listJWTTemplate();
    }

    public function update(string $content): JWTTemplateResponse
    {
        $before = $this->get();

        $after = $this->clients->client()->userManagement()->updateJWTTemplate($content);

        Log::warning(
            'authkit: JWT template updated. Token claims and size may have changed. '.
            'This affects sealed-session parsing (Phase 2 workos guard), zero-HTTP RBAC claims (Phase 5), '.
            'and the Pennant feature_flags claim (Phase 7). The AuthKit sealed session cookie has a 4KB '.
            'ceiling — verify a real login end-to-end after this change before deploying it.',
        );

        Event::dispatch(new JwtTemplateUpdated($before->content, $after->content));

        return $after;
    }
}
```

`JwtTemplateUpdated` is a plain package-native event (not a WorkOS-sourced event — it fires synchronously from our own write path, so it does **not** live under `Events\Workos\*`, which is reserved for the Phase 4 sidecar's WorkOS-origin events):

```php
namespace Authkit\Authkit\Events;

final class JwtTemplateUpdated
{
    public function __construct(
        public readonly string $previousContent,
        public readonly string $newContent,
    ) {}
}
```

Consuming apps can listen for `JwtTemplateUpdated` to wire their own alerting (Slack, PagerDuty, whatever) — the package's obligation is the log line and the event; it cannot force anyone to look at either.

**Implementation steps**:
1. No generator applies. Hand-author `src/JwtTemplates/JwtTemplateManager.php` and `src/Events/JwtTemplateUpdated.php`.
2. Add `jwtTemplate(): JwtTemplateManager` to `src/Authkit.php`.
3. Write `tests/Feature/JwtTemplateTest.php` against `emulate` (SOLID coverage per the brief).
4. Document the 4KB warning prominently in the package README's JWT-template section (not just the log line) — this is called out explicitly in the phase direction as needing to be "loud," and a log line alone is easy to miss in production log volume.

**Feedback loop**:
- Playground: `vendor/bin/pest --filter=JwtTemplate` against emulate's seeded `jwtTemplate` key.
- Parameterized experiment: a dataset of template contents of increasing size (small Liquid template, one that adds several extra claims) asserting the warning fires and the event carries the correct before/after diff regardless of content size — the test does **not** assert actual cookie byte size (that's Phase 2's `SessionSecurity` suite's job), only that this component's warning-and-event contract holds every time `update()` is called.
- Check: same filter; also assert `Log::spy()` received the warning message and `Event::fake()` recorded `JwtTemplateUpdated`.

---

### Component 3 — CORS origin passthrough

**Laravel mechanism**: `Authkit::corsOrigins()` accessor.

**SDK methods wrapped**: `UserManagement::listCorsOrigins(...): PaginatedResponse<CORSOriginResponse>`, `UserManagement::createCorsOrigin(string $origin, ...): CORSOriginResponse`.

**No delete** — see Deviations. `workos-php` v9.1.0's `UserManagement` service exposes only `listCorsOrigins` and `createCorsOrigin`; there is no `deleteCorsOrigin` anywhere in the vendored SDK (`grep -rni cors vendor/workos/workos-php/lib` returns only the list/create methods and their two `CORSOriginResponse`/`CreateCORSOrigin` resource classes).

**Key design** — genuinely trivial, no branching:

```php
namespace Authkit\Authkit\CorsOrigins;

use Authkit\Authkit\WorkosClientManager;
use WorkOS\PaginatedResponse;
use WorkOS\Resource\CORSOriginResponse;

final class CorsOriginManager
{
    public function __construct(private readonly WorkosClientManager $clients) {}

    public function list(?string $before = null, ?string $after = null, ?int $limit = null): PaginatedResponse
    {
        return $this->clients->client()->userManagement()->listCorsOrigins(
            before: $before,
            after: $after,
            limit: $limit,
        );
    }

    public function create(string $origin): CORSOriginResponse
    {
        return $this->clients->client()->userManagement()->createCorsOrigin($origin);
    }
}
```

**Implementation steps**:
1. No generator applies. Hand-author `src/CorsOrigins/CorsOriginManager.php`.
2. Add `corsOrigins(): CorsOriginManager` to `src/Authkit.php`.
3. Write `tests/Feature/CorsOriginsTest.php` against `MockHandler` (emulate coverage unconfirmed — see Open Items).

**Feedback loop**: **Skipped — trivial.** Two one-line passthroughs with no branching logic, no state machine, no caching, no invalidation. A single smoke test (`list()` returns the fixture, `create()` returns the fixture) in the standard suite is sufficient; no dedicated playground/experiment is warranted per the template's own guidance to skip feedback loops for trivial components.

---

### Component 4 — Groups API surface

**Laravel mechanism**: `Authkit::groups()` accessor.

**SDK methods wrapped**:
- `\WorkOS\Service\Groups`: `listOrganizationGroups`, `createOrganizationGroup`, `getOrganizationGroup`, `updateOrganizationGroup`, `deleteOrganizationGroup`, `listGroupOrganizationMemberships`, `createGroupOrganizationMembership`, `deleteGroupOrganizationMembership`
- `\WorkOS\Service\Authorization` (group role assignment methods): `listGroupRoleAssignments`, `createGroupRoleAssignment`, `updateGroupRoleAssignments`, `deleteGroupRoleAssignments`, `getGroupRoleAssignment`, `deleteGroupRoleAssignment`
- `\WorkOS\Service\OrganizationMembershipService::listOrganizationMembershipGroups`

**Key design**:

```php
namespace Authkit\Authkit\Groups;

use Authkit\Authkit\Authorization\FgaManager;
use Authkit\Authkit\WorkosClientManager;
use WorkOS\PaginatedResponse;
use WorkOS\Resource\Group;
use WorkOS\Resource\GroupRoleAssignment;
use WorkOS\Resource\GroupRoleAssignmentList;

final class GroupManager
{
    public function __construct(
        private readonly WorkosClientManager $clients,
        private readonly FgaManager $fga,
    ) {}

    // --- Org groups CRUD ---

    public function list(string $organizationId, ?string $before = null, ?string $after = null, ?int $limit = null): PaginatedResponse
    {
        return $this->clients->client()->groups()->listOrganizationGroups($organizationId, $before, $after, $limit);
    }

    public function create(string $organizationId, string $name, ?string $description = null): Group
    {
        return $this->clients->client()->groups()->createOrganizationGroup($organizationId, $name, $description);
    }

    public function get(string $organizationId, string $groupId): Group
    {
        return $this->clients->client()->groups()->getOrganizationGroup($organizationId, $groupId);
    }

    public function update(string $organizationId, string $groupId, ?string $name = null, ?string $description = null): Group
    {
        return $this->clients->client()->groups()->updateOrganizationGroup($organizationId, $groupId, $name, $description);
    }

    public function delete(string $organizationId, string $groupId): void
    {
        $this->clients->client()->groups()->deleteOrganizationGroup($organizationId, $groupId);
    }

    // --- Group membership (organization membership <-> group) ---

    public function members(string $organizationId, string $groupId, ?string $before = null, ?string $after = null, ?int $limit = null): PaginatedResponse
    {
        return $this->clients->client()->groups()->listGroupOrganizationMemberships($organizationId, $groupId, $before, $after, $limit);
    }

    public function addMember(string $organizationId, string $groupId, string $organizationMembershipId): Group
    {
        return $this->clients->client()->groups()->createGroupOrganizationMembership($organizationId, $groupId, $organizationMembershipId);
    }

    public function removeMember(string $organizationId, string $groupId, string $organizationMembershipId): void
    {
        $this->clients->client()->groups()->deleteGroupOrganizationMembership($organizationId, $groupId, $organizationMembershipId);
    }

    /** Which groups is this organization membership in? */
    public function forMembership(string $organizationMembershipId, ?string $before = null, ?string $after = null, ?int $limit = null): PaginatedResponse
    {
        return $this->clients->client()->organizationMembership()->listOrganizationMembershipGroups(
            $organizationMembershipId, $before, $after, $limit,
        );
    }

    // --- Group role assignments (bust the FGA cache — a group's role on a resource changes effective permissions for every member) ---

    public function roleAssignments(string $groupId, ?string $before = null, ?string $after = null, ?int $limit = null): PaginatedResponse
    {
        return $this->clients->client()->authorization()->listGroupRoleAssignments($groupId, $before, $after, $limit);
    }

    public function assignRole(
        string $groupId,
        string $roleSlug,
        ?string $resourceId = null,
        ?string $resourceExternalId = null,
        ?string $resourceTypeSlug = null,
    ): GroupRoleAssignment {
        $assignment = $this->clients->client()->authorization()->createGroupRoleAssignment(
            $groupId, $roleSlug, $resourceId, $resourceExternalId, $resourceTypeSlug,
        );

        $this->fga->forgetCache();

        return $assignment;
    }

    /** @param array<\WorkOS\Resource\ReplaceGroupRoleAssignmentEntry> $roleAssignments */
    public function replaceRoleAssignments(string $groupId, array $roleAssignments): GroupRoleAssignmentList
    {
        $result = $this->clients->client()->authorization()->updateGroupRoleAssignments($groupId, $roleAssignments);

        $this->fga->forgetCache();

        return $result;
    }

    public function removeRoleAssignmentsByCriteria(
        string $groupId,
        string $roleSlug,
        ?string $resourceId = null,
        ?string $resourceExternalId = null,
        ?string $resourceTypeSlug = null,
    ): void {
        $this->clients->client()->authorization()->deleteGroupRoleAssignments(
            $groupId, $roleSlug, $resourceId, $resourceExternalId, $resourceTypeSlug,
        );

        $this->fga->forgetCache();
    }

    public function removeRoleAssignment(string $groupId, string $roleAssignmentId): void
    {
        $this->clients->client()->authorization()->deleteGroupRoleAssignment($groupId, $roleAssignmentId);

        $this->fga->forgetCache();
    }
}
```

`GroupManager::forgetCache()` calls are a no-op when `authkit.fga.cache.enabled` is `false` (see Component 7 — `FgaManager::forgetCache()` checks the flag itself), so this component has zero behavioral cost when the cache feature is off, which is the common case (opt-in, disabled by default).

**Implementation steps**:
1. No generator applies — plain PHP class, no Eloquent/migration/notification shape.
2. Hand-author `src/Groups/GroupManager.php`.
3. Add `groups(): GroupManager` to `src/Authkit.php` (constructed with both `WorkosClientManager` and `FgaManager` — resolve `FgaManager` from the container rather than `new`-ing it, since it's already a container-bound singleton from Phase 5).
4. Write `tests/Feature/GroupsTest.php` against `MockHandler` — fixture responses for every wrapped method, plus an explicit assertion that `assignRole()`/`replaceRoleAssignments()`/`removeRoleAssignmentsByCriteria()`/`removeRoleAssignment()` each bump the FGA cache generation counter when the cache is enabled, and do nothing to it when disabled.
5. Add the workbench demo route (see File Changes).

**Feedback loop**:
- Playground: `vendor/bin/pest --filter=Groups` (MockHandler) + `composer serve` hitting the workbench demo route.
- Parameterized experiment: a dataset over the four group-role-assignment mutation methods, each asserted to call `FgaManager::forgetCache()` exactly once, with the cache-enabled/disabled state as the second dataset axis (8 cases: 4 methods × 2 config states).
- Check: same filter; green means every CRUD/membership/role-assignment method maps to its exact SDK call with the exact arguments, and cache-busting fires only when configured on.

---

### Component 5 — FGA parent-hierarchy config on `HasWorkosResource`

**Laravel mechanism**: an overridable hook method on the existing `HasWorkosResource` trait (assumed Phase 5 path `src/Concerns/HasWorkosResource.php`) — "nested traits" per the contract's scope-row wording is realized as one trait with an overridable method, not a second trait, because WorkOS enforces **a single parent per resource** (per the WorkOS API facts: "single parent... max 5 hierarchy levels"), so there is exactly one hook to override, not a composable set of trait mixins.

**SDK types used**: `\WorkOS\Service\ParentResourceByExternalId` (constructor: `(string $externalId, string $typeSlug)`), passed into `Authorization::createResource()` / `updateResourceByExternalId()`'s `$parentResource` parameter (assumed to already be called from Phase 5's resource-sync method with `parentResource: null` today).

**Key design**:

```php
trait HasWorkosResource
{
    // ...existing Phase 5 members: workosResourceExternalId(), workosResourceTypeSlug(), syncAsWorkosResource() (assumed names)...

    /**
     * Override to nest this model's FGA resource under another HasWorkosResource
     * model's resource. WorkOS enforces a single parent and a five-level cap
     * (configured per resource type in the Dashboard — there is no API/DSL for it).
     */
    public function workosParentResource(): ?\Illuminate\Database\Eloquent\Model
    {
        return null;
    }

    private function workosParentResourceTarget(): ?\WorkOS\Service\ParentResourceByExternalId
    {
        $parent = $this->workosParentResource();

        if ($parent === null) {
            return null;
        }

        if (! in_array(self::class, class_uses_recursive($parent), true)) {
            throw new \InvalidArgumentException(sprintf(
                '%s::workosParentResource() must return a model using HasWorkosResource, got %s.',
                static::class,
                $parent::class,
            ));
        }

        return new \WorkOS\Service\ParentResourceByExternalId(
            $parent->workosResourceExternalId(),
            $parent->workosResourceTypeSlug(),
        );
    }
}
```

Phase 5's existing `syncAsWorkosResource()` (assumed name) is modified to pass `parentResource: $this->workosParentResourceTarget()` into whichever of `createResource`/`updateResourceByExternalId` it already calls, and to call `app(FgaManager::class)->forgetCache()` after a successful sync (a resource moving in the hierarchy changes effective-permission inheritance for every descendant — the cache must not keep serving pre-move decisions).

**Implementation steps**:
1. Open Phase 5's actual `HasWorkosResource` file. Add `workosParentResource()` and `workosParentResourceTarget()` as shown.
2. Locate the trait's (or its observer's) existing resource-sync call site; add the `parentResource:` argument and the `forgetCache()` call.
3. No generator applies — this is a trait modification, not a new class.
4. Write the parent-hierarchy cases into `tests/Feature/FgaResourceGraphTest.php` (shared file with Component 6 — both are extensions of the same FGA resource-graph capability).

**Feedback loop**:
- Playground: `vendor/bin/pest --filter=FgaResourceGraph` against `MockHandler` (no `resources` support in emulate's seed schema).
- Parameterized experiment: a dataset of parent chains (0, 1, and 5 levels — the WorkOS-enforced cap) asserting the correct `ParentResourceByExternalId` is sent at each depth, plus a case asserting the `InvalidArgumentException` when `workosParentResource()` returns a model that doesn't use the trait.
- Check: same filter; green means the parent target resolves correctly at every depth and the guard rejects malformed overrides before any HTTP call is made.

---

### Component 6 — FGA resource-discovery helpers

**Laravel mechanism**: two new public methods on the same `FgaManager` (Phase 5) behind `Authkit::fga()`.

**SDK methods wrapped** (`\WorkOS\Service\Authorization`):
- `listResourcesForMembership(string $organizationMembershipId, ParentResourceById|ParentResourceByExternalId $parentResource, string $permissionSlug, ?before, ?after, ?limit, PaginationOrder $order, ?RequestOptions): PaginatedResponse<AuthorizationResource>` — "what can this membership access under this parent?"
- `listMembershipsForResource(string $resourceId, string $permissionSlug, ?before, ?after, ?limit, PaginationOrder $order, ?AuthorizationAssignment $assignment, ?RequestOptions): PaginatedResponse<UserOrganizationMembershipBaseListData>` — "who can access this resource?" (by internal resource ID)
- `listMembershipsForResourceByExternalId(string $organizationId, string $resourceTypeSlug, string $externalId, string $permissionSlug, ...): PaginatedResponse<UserOrganizationMembershipBaseListData>` — same, by external ID + org + type slug

**SDK naming hazard worth calling out explicitly**: `workos-php` v9.1.0 has *three* near-identical parent/target value-object pairs generated per-endpoint by its `oagen` codegen, and they are **not interchangeable**:

| Class pair | Used by | Property names |
|---|---|---|
| `ParentResourceById` / `ParentResourceByExternalId` | `listResourcesForMembership`, `createResource`, `updateResource`, `updateResourceByExternalId` | `id` / (`externalId`, `typeSlug`) |
| `ParentById` / `ParentByExternalId` | `listResources` (top-level resource listing — **not used by this phase**) | `resourceId` / (`resourceTypeSlug`, `externalId`) |
| `ResourceTargetById` / `ResourceTargetByExternalId` | `check()` (Phase 5) | `resourceId` / (`resourceExternalId`, `resourceTypeSlug`) |

Passing a `ParentById` where `ParentResourceById` is expected (or vice versa) is a type error PHPStan will catch at analysis time, but only if the implementer reaches for the right symbol in the first place — this table exists so they don't have to grep the SDK to find out which one.

**Key design** — extends Phase 5's assumed `ResourceTarget` value object with a second conversion method so callers keep using the one vocabulary (`ResourceTarget::byId()` / `byExternalId()`) for both `check()` and the new discovery calls:

```php
// Addition to Phase 5's src/Authorization/ResourceTarget.php (assumed shape: private readonly ?string $id, ?string $externalId, ?string $typeSlug)
public function toParentTarget(): \WorkOS\Service\ParentResourceById|\WorkOS\Service\ParentResourceByExternalId
{
    return $this->id !== null
        ? new \WorkOS\Service\ParentResourceById($this->id)
        : new \WorkOS\Service\ParentResourceByExternalId($this->externalId, $this->typeSlug);
}
```

```php
// Additions to src/Authorization/FgaManager.php
public function listResourcesForMembership(
    string $organizationMembershipId,
    ResourceTarget $parentResource,
    string $permissionSlug,
    ?string $before = null,
    ?string $after = null,
    ?int $limit = null,
): PaginatedResponse {
    return $this->clients->client()->authorization()->listResourcesForMembership(
        organizationMembershipId: $organizationMembershipId,
        parentResource: $parentResource->toParentTarget(),
        permissionSlug: $permissionSlug,
        before: $before,
        after: $after,
        limit: $limit,
    );
}

public function listMembershipsForResource(
    string $resourceId,
    string $permissionSlug,
    ?string $before = null,
    ?string $after = null,
    ?int $limit = null,
    ?AuthorizationAssignment $assignment = null,
): PaginatedResponse {
    return $this->clients->client()->authorization()->listMembershipsForResource(
        resourceId: $resourceId,
        permissionSlug: $permissionSlug,
        before: $before,
        after: $after,
        limit: $limit,
        assignment: $assignment,
    );
}

public function listMembershipsForResourceByExternalId(
    string $organizationId,
    string $resourceTypeSlug,
    string $externalId,
    string $permissionSlug,
    ?string $before = null,
    ?string $after = null,
    ?int $limit = null,
    ?AuthorizationAssignment $assignment = null,
): PaginatedResponse {
    return $this->clients->client()->authorization()->listMembershipsForResourceByExternalId(
        organizationId: $organizationId,
        resourceTypeSlug: $resourceTypeSlug,
        externalId: $externalId,
        permissionSlug: $permissionSlug,
        before: $before,
        after: $after,
        limit: $limit,
        assignment: $assignment,
    );
}
```

`\WorkOS\Resource\AuthorizationAssignment` (`Direct`/`Indirect` enum) is a plain backed enum with no business meaning to hide — it is fine for consumer code to reference it directly (it carries no live SDK state, just a two-case string enum, same category as e.g. Laravel's own enums); re-read the doctrine's intent (no direct SDK *class/service* usage) rather than its letter if this becomes ambiguous elsewhere in the codebase.

**Implementation steps**:
1. No generator applies. Add the `toParentTarget()` method to Phase 5's `ResourceTarget`.
2. Add the three methods above to Phase 5's `FgaManager`.
3. Write `tests/Feature/FgaResourceGraphTest.php` (shared with Component 5) against `MockHandler`.

**Feedback loop**:
- Playground: `vendor/bin/pest --filter=FgaResourceGraph` (MockHandler).
- Parameterized experiment: a dataset crossing `{byId, byExternalId}` parent targeting × `{listResourcesForMembership, listMembershipsForResource, listMembershipsForResourceByExternalId}`, asserting the correct SDK method and argument shape for each combination.
- Check: same filter; green means every discovery helper sends the right request shape for both ID-based and external-ID-based targeting.

---

### Component 7 — Opt-in FGA check cache with events-driven invalidation

This is the largest and highest-risk component in the phase — the one place local state (a cache, not a projection) sits in front of a canonical WorkOS decision.

**Laravel mechanism**: a cache-aware wrapper around Phase 5's existing `FgaManager::check()`, gated by config, plus a listener registered in `AuthkitServiceProvider::boot()` only when the feature is enabled.

**Config** (new keys under the `fga` namespace Phase 5 is assumed to have introduced in `config/authkit.php`):

```php
'fga' => [
    // ...existing Phase 5 keys...
    'cache' => [
        'enabled' => (bool) env('AUTHKIT_FGA_CACHE_ENABLED', false),
        'ttl' => (int) env('AUTHKIT_FGA_CACHE_TTL', 300),
        'store' => env('AUTHKIT_FGA_CACHE_STORE'), // null = app's default cache store
    ],
],
```

**Cache key design — generation-versioned, not tag-based.** The SDK's Check API has **no batch-check endpoint**, and a real FGA deployment can have an unbounded number of distinct `(organizationMembershipId, permissionSlug, resourceTarget)` triples cached at once. Point-invalidation (finding and deleting exactly the keys affected by one event) would require either cache-tag support (not available on every Laravel cache driver — `file` and `database` don't support tags) or a secondary index of which keys exist for which membership/resource (which is itself new local state, forbidden by the projection-boundary doctrine). Instead, every cached decision is keyed under a **generation number**:

```
authkit:fga:check:g{generation}:{organizationMembershipId}:{permissionSlug}:{resourceKey}
```

Invalidation is a single atomic increment of `authkit:fga:cache:generation` — every previously-cached key becomes unreachable (it's still sitting in the store under a lower generation number, but nothing will ever ask for that key again, so it just ages out via TTL). This trades some cache-store memory for driver-portability and O(1) invalidation, and is deliberately coarse: **any** relevant event busts the **entire** cache, not just the affected membership/resource. That coarseness is the documented staleness/precision trade-off this component makes — see Failure Modes.

```php
namespace Authkit\Authkit\Authorization;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class FgaManager
{
    // ...existing Phase 5 members (client manager, listResourcesForMembership, etc. from Component 6)...

    public function check(string $organizationMembershipId, string $permissionSlug, ResourceTarget $target): bool
    {
        if (! config('authkit.fga.cache.enabled')) {
            return $this->rawCheck($organizationMembershipId, $permissionSlug, $target);
        }

        $store = Cache::store(config('authkit.fga.cache.store'));
        $key = $this->cacheKey($store, $organizationMembershipId, $permissionSlug, $target);

        try {
            if ($store->has($key)) {
                return (bool) $store->get($key);
            }
        } catch (\Throwable $e) {
            Log::warning('authkit: FGA cache read failed, bypassing cache for this check', ['exception' => $e->getMessage()]);

            return $this->rawCheck($organizationMembershipId, $permissionSlug, $target);
        }

        $authorized = $this->rawCheck($organizationMembershipId, $permissionSlug, $target);

        try {
            $store->put($key, $authorized, config('authkit.fga.cache.ttl'));
        } catch (\Throwable $e) {
            Log::warning('authkit: FGA cache write failed, result not cached', ['exception' => $e->getMessage()]);
        }

        return $authorized;
    }

    /** Phase 5's original check() body, renamed. The direct, uncached Check API call. */
    private function rawCheck(string $organizationMembershipId, string $permissionSlug, ResourceTarget $target): bool
    {
        return $this->clients->client()->authorization()->check(
            $organizationMembershipId,
            $permissionSlug,
            $target->toSdk(),
        )->authorized;
    }

    public function forgetCache(): void
    {
        if (! config('authkit.fga.cache.enabled')) {
            return;
        }

        Cache::store(config('authkit.fga.cache.store'))->increment('authkit:fga:cache:generation');
    }

    private function cacheKey(\Illuminate\Contracts\Cache\Repository $store, string $organizationMembershipId, string $permissionSlug, ResourceTarget $target): string
    {
        // Default is 0, not 1. Laravel's increment() on a key that has never been set
        // falls back to forever($key, 1) on every driver (confirmed against the vendored
        // ArrayStore/FileStore source) — so the very first forgetCache() call sets the
        // generation to 1. If the implicit default here also read 1, that first
        // invalidation would be invisible: pre- and post-invalidation keys would collide
        // under the same generation number and stale entries would stay reachable.
        $generation = $store->get('authkit:fga:cache:generation', 0);

        return sprintf(
            'authkit:fga:check:g%d:%s:%s:%s',
            $generation,
            $organizationMembershipId,
            $permissionSlug,
            $target->cacheFragment(), // e.g. "id:res_123" or "ext:project:proj_42" — add this method to ResourceTarget alongside toSdk()/toParentTarget()
        );
    }
}
```

**Invalidation listener** — subscribes to both the typed membership events (Phase 4, since memberships are a declared projection) and the generic fallback (since role, organization-role, permission, and group WorkOS events are **not** in the bounded typed set per the contract's typed-events decision — see the confirmed catalog note on `isAuthorizationRelevant()` below):

```php
namespace Authkit\Authkit\Authorization\Listeners;

use Authkit\Authkit\Authorization\FgaManager;
use Authkit\Authkit\Events\Workos\GenericWorkosEvent;
use Authkit\Authkit\Events\Workos\OrganizationMembershipCreated;
use Authkit\Authkit\Events\Workos\OrganizationMembershipDeleted;
use Authkit\Authkit\Events\Workos\OrganizationMembershipUpdated;

final class InvalidateFgaCache
{
    public function __construct(private readonly FgaManager $fga) {}

    public function handleMembershipEvent(
        OrganizationMembershipCreated|OrganizationMembershipUpdated|OrganizationMembershipDeleted $event,
    ): void {
        $this->fga->forgetCache();
    }

    public function handleGenericEvent(GenericWorkosEvent $event): void
    {
        if ($this->isAuthorizationRelevant($event->type)) {
            $this->fga->forgetCache();
        }
    }

    /**
     * Confirmed against the live WorkOS Events API type catalog (workos.com/docs/events,
     * exhaustive category-by-category pull done during spec review — see Open Item #1):
     * there is NO `role_assignment.*`, `authorization_resource.*`, or
     * `group_role_assignment.*` event type anywhere in the catalog. Assigning a role to a
     * membership/resource (RBAC or FGA) and editing the authorization-resource hierarchy
     * both produce no event at all — see Failure Mode #1 for what that means for staleness.
     * The real, existing types that can shift a check() outcome are role and permission
     * *definition* changes and group changes (a group's membership list, not its role
     * assignment, since there is no event for the latter either):
     */
    private function isAuthorizationRelevant(string $type): bool
    {
        return str_starts_with($type, 'role.')
            || str_starts_with($type, 'organization_role.')
            || str_starts_with($type, 'permission.')
            || str_starts_with($type, 'group.');
    }
}
```

Registered conditionally in `AuthkitServiceProvider::boot()` (only when the feature is on — no reason to attach listeners for a cache that's disabled):

```php
if (config('authkit.fga.cache.enabled')) {
    Event::listen(
        [OrganizationMembershipCreated::class, OrganizationMembershipUpdated::class, OrganizationMembershipDeleted::class],
        [InvalidateFgaCache::class, 'handleMembershipEvent'],
    );
    Event::listen(GenericWorkosEvent::class, [InvalidateFgaCache::class, 'handleGenericEvent']);
}
```

**Implementation steps**:
1. Scaffold the listener's file shape with the real Laravel generator, then relocate it into the package's own namespace (the generator assumes an application context, which only the workbench/testbench sandbox provides):
   ```bash
   vendor/bin/testbench make:listener InvalidateFgaCache --invokable
   ```
   Move the generated file from wherever testbench placed it (workbench's app/Listeners) into `src/Authorization/Listeners/InvalidateFgaCache.php`, change its namespace to `Authkit\Authkit\Authorization\Listeners`, and replace the generated `__invoke()` stub with the two named handler methods shown above (two methods because it needs two different event-type unions, which an invokable single-method listener can't cleanly express — keep the file, drop the `--invokable` shape).
2. Rename Phase 5's `FgaManager::check()` body to `private rawCheck()`; add the new public `check()` wrapper, `forgetCache()`, and `cacheKey()` as shown. No existing call site (Gate integration, `HasWorkosResource` policy bridge) changes — the public `check()` signature is unchanged.
3. Add `ResourceTarget::cacheFragment(): string` (e.g. `"id:{$this->id}"` or `"ext:{$this->typeSlug}:{$this->externalId}"`).
4. Add the `fga.cache` config block to `config/authkit.php`.
5. Register the listener conditionally in `AuthkitServiceProvider::boot()`.
6. Write `tests/Feature/FgaCacheTest.php` against `MockHandler` + Laravel's `array` cache store (set via `config(['cache.default' => 'array'])` in the test's setup, or `Cache::store('array')` directly — no real Redis/Memcached needed for correctness assertions).

**Feedback loop**:
- Playground: `vendor/bin/pest --filter=FgaCache` (MockHandler + array cache store, fully in-process, no external services).
- Parameterized experiment: a dataset crossing cache state × trigger:
  - disabled → always calls the API, never touches the cache store
  - enabled, cold cache → API call + cache write
  - enabled, warm cache → no API call, cache read only
  - enabled, warm cache + membership event fires → next check() is an API call again (generation bumped)
  - enabled, warm cache + generic event with an authorization-relevant type → same
  - enabled, warm cache + generic event with an irrelevant type (e.g. a Directory Sync event type) → cache still warm, no API call
  - enabled, cache store throws on read → falls through to a live API call, does not crash, does not silently deny
  - enabled, cache store throws on write → the check's own return value is still correct (from the live call), only the caching side-effect is lost
- Check: same filter; green means every branch in `check()`/`forgetCache()`/the listener is exercised, and specifically that a cache-backend failure never changes the authorization *outcome*, only whether it was served from cache.

## File Changes

Every row traces to a scope item above. "Scope item" column uses the numbering from **Scope Rows Implemented**.

### New files

| Path | Scope item | Purpose |
|---|---|---|
| `src/Invitations/InvitationManager.php` | 1 | Invitations facade backend |
| `src/JwtTemplates/JwtTemplateManager.php` | 2 | JWT template get/update + loud warning |
| `src/Events/JwtTemplateUpdated.php` | 2 | Package-native event fired on template update |
| `src/CorsOrigins/CorsOriginManager.php` | 2 | CORS origin list/create |
| `src/Groups/GroupManager.php` | 3 | Org groups CRUD, group membership, group role assignments, `listOrganizationMembershipGroups` |
| `src/Authorization/Listeners/InvalidateFgaCache.php` | 5 | Cache invalidation listener (membership + generic events) |
| `tests/Feature/InvitationsTest.php` | 1 | Emulate-backed |
| `tests/Feature/JwtTemplateTest.php` | 2 | Emulate-backed |
| `tests/Feature/CorsOriginsTest.php` | 2 | MockHandler-backed |
| `tests/Feature/GroupsTest.php` | 3 | MockHandler-backed |
| `tests/Feature/FgaResourceGraphTest.php` | 4 | MockHandler-backed; covers Components 5 and 6 |
| `tests/Feature/FgaCacheTest.php` | 5 | MockHandler + array cache store |

### Modified files

| Path | Scope item | Change |
|---|---|---|
| `src/Authkit.php` | 1, 2, 3, 4 | Add `invitations()`, `jwtTemplate()`, `corsOrigins()`, `groups()` accessor methods |
| `src/AuthkitServiceProvider.php` | 5 | Register `InvalidateFgaCache` listener in `boot()`, gated on `config('authkit.fga.cache.enabled')` |
| `config/authkit.php` (Phase 1's rename of `authkit-laravel.php`) | 5 | Add `fga.cache.{enabled,ttl,store}` keys |
| `src/Concerns/HasWorkosResource.php` (Phase 5, assumed path) | 4 | Add `workosParentResource()` hook + `workosParentResourceTarget()`; existing resource-sync method gains `parentResource:` argument and a post-sync `forgetCache()` call |
| `src/Authorization/ResourceTarget.php` (Phase 5, assumed path) | 4 | Add `toParentTarget()` and `cacheFragment()` methods |
| `src/Authorization/FgaManager.php` (Phase 5, assumed path) | 4, 5 | Add `listResourcesForMembership()`, `listMembershipsForResource()`, `listMembershipsForResourceByExternalId()`; rename existing `check()` body to `rawCheck()`, add cache-aware `check()`, `forgetCache()`, `cacheKey()` |
| `workbench/routes/web.php` | 1, 3 | Append demo routes for Invitations and Groups (see below) |
| `README.md` | 2 | Add a prominent (not just a log-line) warning section on JWT template edits and the 4KB sealed-cookie ceiling |

No new `database/migrations/*`, no new Eloquent models, no new config *files* (one existing file gets new keys) — consistent with the projection-boundary doctrine: nothing in this phase is WorkOS-shaped local state that needs a table.

### Workbench demo routes (append to `workbench/routes/web.php`)

```php
use Authkit\Authkit\Facades\Authkit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/depth-extensions/invitations', function () {
    return Authkit::invitations()->list(organizationId: config('workbench.demo_organization_id'))
        ->data;
});

Route::post('/depth-extensions/invitations', function (Request $request) {
    return Authkit::invitations()->send(
        email: $request->string('email')->toString(),
        organizationId: config('workbench.demo_organization_id'),
    );
});

Route::get('/depth-extensions/groups', function () {
    return Authkit::groups()->list(organizationId: config('workbench.demo_organization_id'))
        ->data;
});
```

No `use WorkOS\...` import and no `\WorkOS\` fully-qualified reference appears in this snippet — it satisfies the G2 grep enforcement (`grep -rE '(use |\\)WorkOS\\' workbench/`) the same way every other workbench example must. `config('workbench.demo_organization_id')` is assumed to exist by Phase 3 (the org-context phase); if Phase 3 named it differently, use whatever the workbench's actual demo-organization config key is.

## Service Provider Registration Diff

```php
// src/AuthkitServiceProvider.php — boot(), added after existing registrations

use Authkit\Authkit\Authorization\Listeners\InvalidateFgaCache;
use Authkit\Authkit\Events\Workos\GenericWorkosEvent;
use Authkit\Authkit\Events\Workos\OrganizationMembershipCreated;
use Authkit\Authkit\Events\Workos\OrganizationMembershipDeleted;
use Authkit\Authkit\Events\Workos\OrganizationMembershipUpdated;
use Illuminate\Support\Facades\Event;

public function boot(): void
{
    // ...existing route/view/lang loading...

    if ($this->app['config']->get('authkit.fga.cache.enabled')) {
        Event::listen(
            [OrganizationMembershipCreated::class, OrganizationMembershipUpdated::class, OrganizationMembershipDeleted::class],
            [InvalidateFgaCache::class, 'handleMembershipEvent'],
        );
        Event::listen(GenericWorkosEvent::class, [InvalidateFgaCache::class, 'handleGenericEvent']);
    }

    // ...existing console-only publishes/commands guard...
}
```

`register()` is unchanged — no new container bindings are needed. `InvitationManager`, `JwtTemplateManager`, `CorsOriginManager`, and `GroupManager` are constructed directly inside `Authkit.php`'s new accessor methods (matching the existing skeleton pattern of `Authkit` as a thin accessor hub, not a service-locator-registered set of bindings), pulling `WorkosClientManager` (and, for `GroupManager`, `FgaManager`) from the container at the point the accessor is called:

```php
// src/Authkit.php — additions
public function invitations(): Invitations\InvitationManager
{
    return new Invitations\InvitationManager($this->clients);
}

public function jwtTemplate(): JwtTemplates\JwtTemplateManager
{
    return new JwtTemplates\JwtTemplateManager($this->clients);
}

public function corsOrigins(): CorsOrigins\CorsOriginManager
{
    return new CorsOrigins\CorsOriginManager($this->clients);
}

public function groups(): Groups\GroupManager
{
    return new Groups\GroupManager($this->clients, app(Authorization\FgaManager::class));
}
```

(`$this->clients` is assumed to be `Authkit`'s existing injected `WorkosClientManager` property from Phase 1 — if the real property/constructor differs, wire the same way the existing `connect()`/`pipes()` accessors do.)

## Testing Requirements

| Suite | Path | Key cases | Edge/error cases | Test path | Seed data |
|---|---|---|---|---|---|
| Invitations | `tests/Feature/InvitationsTest.php` | send → list → get → resend → revoke; send → accept; findByToken; acceptUrl() returns the SDK-provided URL verbatim | resend/revoke on an already-revoked invitation surfaces the WorkOS `ApiException` uncaught (no swallowing) | emulate | emulate's `invitations` seed key, or invitations created live via `send()` in test setup |
| JWT Template | `tests/Feature/JwtTemplateTest.php` | get(); update() returns new content; update() logs the warning; update() dispatches `JwtTemplateUpdated` with correct before/after | update() with identical content still fires the warning + event (no "no-op" special case — every write is loud) | emulate | emulate's `jwtTemplate` seed key |
| CORS Origins | `tests/Feature/CorsOriginsTest.php` | list() maps fixture correctly; create() maps fixture correctly | none beyond the standard shared failure-mode prompts (no branching to test) | MockHandler | Fixture `CORSOriginResponse` JSON |
| Groups | `tests/Feature/GroupsTest.php` | full org-groups CRUD; member add/remove; `forMembership()`; group role assignment CRUD (assign/replace/remove-by-criteria/remove-by-id) | each role-assignment mutation bumps the cache generation when `authkit.fga.cache.enabled` is true and does nothing when false | MockHandler (emulate has no group endpoints) | Fixture `Group`/`GroupRoleAssignment` JSON per method |
| FGA Resource Graph | `tests/Feature/FgaResourceGraphTest.php` | parent-hierarchy resolution at depth 0/1/5; malformed-parent guard exception; `listResourcesForMembership`/`listMembershipsForResource`/`listMembershipsForResourceByExternalId` for both by-ID and by-external-ID targeting | resource sync's post-sync `forgetCache()` call fires only when cache is enabled | MockHandler (emulate seed has no `resources`/`resourceTypes` key) | Fixture `AuthorizationResource`/`UserOrganizationMembershipBaseListData` JSON |
| FGA Cache | `tests/Feature/FgaCacheTest.php` | disabled/cold/warm/invalidated matrix (see Component 7's dataset); cache-read-throws and cache-write-throws fail-open behavior | never returns a different authorization outcome due to a cache-layer fault | MockHandler + Laravel `array` cache store | Fixture `AuthorizationCheck` JSON (`{"authorized": true}` / `{"authorized": false}`) |

All six suites tag `->group('depth-extensions')` so `vendor/bin/pest --group=depth-extensions` runs the whole phase.

## Failure Modes

| # | Named failure | Component | What happens | Mitigation / documented behavior |
|---|---|---|---|---|
| 1 | **Stale cache after Dashboard-originated authorization change** | 7 | Two different cases, confirmed against the live WorkOS Events API catalog during spec review (see Open Item #1): **(a)** a role/permission *definition* edit or a group create/update/delete/membership change made directly in the WorkOS Dashboard (not through this package) is invisible to the cache until the Phase 4 events sidecar polls, dispatches the matching Laravel event, and this phase's listener bumps the generation counter. **(b)** a role *assignment* (who holds which role on what — RBAC or FGA) or an authorization-resource *hierarchy* edit made the same way is invisible to the cache for the **full TTL, with no events-driven backstop at all** — the catalog has no `role_assignment.*`, `authorization_resource.*`, or `group_role_assignment.*` event type, so no event will ever fire to bust the cache for either of these two change types | For case (a): documented staleness bound = events-poll interval + cache TTL — not a defect, exactly what "opt-in cache, events-driven invalidation" means. **For case (b): the staleness bound is cache TTL only** — there is no events-driven backstop for role-assignment or resource-hierarchy Dashboard edits with the current WorkOS event catalog, so this is a real, documented gap rather than a "poll interval + TTL" bound. Operators doing security-sensitive, revocation-critical work through the Dashboard should keep the cache disabled, or set a TTL short enough to be the *only* invalidation mechanism they're relying on for case (b) |
| 2 | **Self-write skew: Phase 5 `RoleManager`/RBAC role-assignment methods don't proactively bust the cache** | 7 | Calling Phase 5's pre-existing `assignRole()`/`removeRole()` (RBAC role assignment on a membership, not FGA-specific) through this package changes effective FGA-check outcomes for any resource that role touches, but that write path predates this phase and is not modified here — only the async events listener eventually catches it | Deliberately out of scope for this delta (see Working Rules: stay in scope, don't modify unrelated files). Documented as a residual gap; closing it is a natural, small follow-up patch to Phase 5's `RoleManager` (add the same `forgetCache()` call this phase adds to `GroupManager`'s equivalent methods) — flagged as an Open Item below, not silently accepted |
| 3 | **Cache backend unavailable or throws** | 7 | Redis connection drop, misconfigured store name, serialization failure, etc. | `check()` catches `\Throwable` on both read and write, logs a warning, and **falls through to a live API call** — a cache fault changes performance, never the authorization outcome. Explicitly the opposite of a naive `try { $cached } catch { return false; }` (which would be a silent authorization *outage*) or `catch { return true; }` (a silent authorization *bypass*) |
| 4 | **Oversized sealed cookie after a JWT template edit** | 2 | `updateJWTTemplate()` succeeds at WorkOS; if the new template grows the claim set (e.g., embedding large role/permission arrays), the next login's sealed session cookie may approach or exceed the 4KB-per-cookie ceiling the sealed-session doctrine is built around, silently truncated or dropped by the browser — Phase 2's `workos` guard then fails to unseal (or the cookie never arrives), and the user is stuck unable to log in | The loud `Log::warning` + `JwtTemplateUpdated` event exist precisely so this is caught in review/staging, not discovered by a locked-out production user. README calls out the prescribed WorkOS mitigation pattern (drop bulky claims from the template, rely on the runtime API/claims-poll fallback Phases 5/7 already use) rather than growing the token |
| 5 | **Concurrent reads around an invalidation boundary** | 7 | (a) many requests miss a just-busted cache key simultaneously and all call the live Check API — redundant but harmless, no side effects on a `check()` call; (b) a request that read the pre-invalidation generation number, computed its result, and writes it back *after* the generation bump completes, briefly repopulating a cache entry computed against stale state | (a) accepted, self-healing, bounded by normal request concurrency, not worth engineering around. (b) bounded by TTL — the mis-cached entry expires like any other and is never a *permanent* wrong answer, only a bounded-duration one on top of the already-documented staleness bound in #1 |
| 6 | **Double-dispatched invitation email on retry** | 1 | A queued job or client retry calls `send()` twice for the same intended invitation, e-mailing the recipient twice and (depending on WorkOS's own dedup behavior) potentially creating two invitation records | `send()` exposes an optional `$idempotencyKey` plumbed to `RequestOptions(idempotencyKey: ...)`; callers on a retryable code path (queued jobs) should always pass one. Callers that don't (one-shot HTTP-request-triggered sends) accept the risk the same way the SDK's own retry-on-429/5xx behavior already does for every other write in the package |
| 7 | **WorkOS unreachable / 5xx exhausts retries** | all six | Any wrapped call ultimately throws `\WorkOS\Exception\ApiException` (or a transport exception) after the SDK's built-in retry budget (429/5xx with jitter + `Retry-After`) is exhausted | None of these components catch or translate that exception — it surfaces to the caller uncaught, same as every other feature-area wrapper in the package. No new error-handling abstraction is introduced here; this is a shared prompt, not a phase-specific design decision |
| 8 | **`CorsOriginManager` has no `delete()` method** | 3 | Code expecting CORS-origin deletion (the phase direction's literal ask) gets a PHPStan/static "method does not exist" failure, not a runtime error | Not a runtime failure mode — a scope boundary. See Deviations. If WorkOS ships a delete endpoint in a later SDK major/minor, add `delete(string $id)` to `CorsOriginManager` then; nothing about this phase's design blocks that addition |

## Deviations from `spec-template-feature-area.md`

1. **CORS origin delete is not implemented.** The phase-specific direction asks for "CORS origin passthroughs (list/create/delete)," but `workos/workos-php` v9.1.0 (the version pinned in `composer.json`, vendored and inspected directly — `grep -rni cors vendor/workos/workos-php/lib` returns only `listCorsOrigins`/`createCorsOrigin` and their two resource classes) has no delete endpoint on `UserManagement` or anywhere else in the SDK. `CorsOriginManager` ships with `list()` and `create()` only. This is a hard SDK ceiling, not a design choice — flagged loudly rather than fabricated.
2. **FGA cache uses generation-versioned keys, not point invalidation or cache tags.** The template's shared conventions don't prescribe a caching strategy (this is the first cache in the package), so this is a new pattern, not a deviation from an established one — noted here because it's a deliberate, non-obvious design choice worth surfacing to reviewers: it trades invalidation precision for cache-store portability (works identically on `file`, `database`, `array`, and tag-capable stores like `redis`/`memcached`) and O(1) invalidation cost, at the cost of busting the *entire* FGA check cache on any relevant event rather than just the affected keys.
3. **Two of the five sub-features get workbench demo routes (Invitations, Groups); three do not (JWT Template, CORS Origins, FGA).** The contract's own reasoning for these being Full-tier rather than MVP calls JWT/CORS "dashboard-adjacent management APIs, rarely touched at runtime," and FGA resource-graph/cache has no natural request/response shape to demo (it's cross-cutting behavior on an existing `check()` call, not a new user-facing capability). These three are exercised through their Pest suites only, per the template's own flexibility ("any workbench example **added** for this area" — not every area needs one).
4. **The invitation flows are exposed as a facade only, no `FormRequest` subclass**, despite the contract scope row's wording ("facade/form-request helpers"). The Phase 2 form-request pattern exists to give custom-controller apps parity with the registered login/logout/callback *routes* — a real HTTP submission shape with validation rules. Nothing in Invitations has that shape (send/resend/revoke are management-API calls a backend job or admin panel triggers, not a browser form post with validation concerns), so a `FormRequest` here would be a form without a corresponding route, the exact "speculative abstraction" the shared conventions warn against ("must map to a real WorkOS capability — no speculative abstraction"). If a future starter-kit build genuinely needs an HTTP-submittable invite form, that's the starter kit's own `FormRequest` calling `Authkit::invitations()->send()`, not a package-level one.

## Open Items

1. **[Resolved during spec review]** The WorkOS Events API type-string catalog was pulled exhaustively, category by category, from `workos.com/docs/events`: there is no `role_assignment.*`, `authorization_resource.*`, or `group_role_assignment.*` event type anywhere in it — role assignment (RBAC or FGA) and authorization-resource hierarchy edits produce no event at all, confirmed rather than assumed. The real, existing authorization-adjacent types are `role.*`, `organization_role.*`, `permission.*`, and `group.*` (all four confirmed present); `isAuthorizationRelevant()` now filters on exactly these four prefixes instead of the three unconfirmed, non-existent ones this spec originally guessed. The residual gap this surfaces — role-assignment and resource-hierarchy Dashboard edits have no events-driven cache-invalidation path at all, only TTL — is documented in Failure Mode #1 case (b) rather than left as an unstated assumption. Re-confirm at implementation time in case the catalog has grown a dedicated event for either gap since this review.
2. **Confirm CORS-origin coverage in `workos/emulate`.** The brief's emulate coverage notes don't mention CORS origins at all (not in SOLID, PARTIAL, or ZERO lists). This spec defaults to `MockHandler` for `CorsOriginsTest` as the safer assumption; if emulate turns out to cover it, moving the suite to emulate is a welcome simplification, not required.
3. **Self-write skew on Phase 5's RBAC role-assignment methods** (Failure Mode #2) is a known, accepted gap in this phase's scope. A follow-up (in Phase 5 or a later patch) should add the same `FgaManager::forgetCache()` call this phase adds to `GroupManager`'s role-assignment methods.
4. **Every assumed Phase 3/4/5 class name and path in this document is a placeholder for "whatever those phases actually named it."** Reconcile against the real code before implementing; the design (what wraps what, what invalidates what) is what's load-bearing, not the exact strings `FgaManager`/`ResourceTarget`/`HasWorkosResource`/`WorkosClientManager`.
5. **`workbench` config key for the demo organization ID** (`config('workbench.demo_organization_id')`) is assumed to exist by Phase 3; confirm the real key name when Phase 3 lands and adjust the two new workbench routes accordingly.

## Validation Commands

```bash
composer analyse          # PHPStan (larastan)
composer lint:check       # Pint check-only
composer test:types       # Pest type coverage --min=100
vendor/bin/pest --filter=Invitations
vendor/bin/pest --filter=JwtTemplate
vendor/bin/pest --filter=CorsOrigins
vendor/bin/pest --filter=Groups
vendor/bin/pest --filter=FgaResourceGraph
vendor/bin/pest --filter=FgaCache
vendor/bin/pest --group=depth-extensions   # all six suites together
composer test              # full chain — must be green before commit
```
