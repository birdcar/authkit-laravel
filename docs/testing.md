# Testing

First-party fakes for writing fast, offline Pest (or PHPUnit) feature tests
against every surface of this package — the `Queue::fake()` idea applied to
WorkOS. No network, no `WORKOS_API_KEY`, no emulator process.

Two entry points cover everything:

- **`Authkit::actingAs($user, [...])`** — a synthetic `workos` session:
  guard, claims, Gate, current organization, and feature flags all behave as
  if the user had really logged in.
- **`Authkit::fake([...])`** — swaps manager bindings for in-memory fakes
  that record calls and expose `assert*` helpers.

Every example below runs with zero HTTP — and Laravel's native HTTP testing
idioms guard that for you, because WorkOS traffic rides the application's
own HTTP client (`authkit.http.transport`, default `laravel`):

```php
Http::preventStrayRequests();   // any WorkOS SDK call now fails loudly

Http::fake(['api.workos.com/*' => Http::response([...])]);  // or serve wire payloads
Http::assertSent(fn ($request) => str_contains($request->url(), 'invitations'));
```

No package-specific incantation needed — `Http::fake()` and friends see
WorkOS requests exactly like any other outbound call. (Set
`AUTHKIT_HTTP_TRANSPORT=guzzle` to restore the SDK's bare Guzzle transport;
under it, Laravel's HTTP fakes cannot see WorkOS traffic and the offline
guard is a Guzzle `HandlerStack` bound with an empty `MockHandler` queue.)

## Where this sits next to `npx @workos/emulate`

The [WorkOS emulator](https://www.npmjs.com/package/@workos/emulate) is great
for end-to-end acceptance runs against real HTTP, and this package's own
suite uses it that way. For a consuming app's everyday feature tests it is
the wrong tool: it needs a running process, and it has no route coverage at
all for Vault, Pipes, or Groups, only partial CORS/audit-export coverage,
and no org-scoped API key creation. The fakes cover the full manager
surface, in-process.

## `Authkit::actingAs()`

```php
use Authkit\Authkit\Facades\Authkit;

it('lets an admin delete projects', function () {
    $user = User::factory()->create(['workos_id' => 'user_123']);
    $team = Team::factory()->create(['workos_id' => 'org_123']);

    Authkit::actingAs($user, [
        'organization' => $team,            // model with a workos_id, or a raw 'org_...' string
        'role' => 'admin',
        'permissions' => ['projects.delete', 'team.manage'],
        'feature_flags' => ['team-plan'],
    ]);

    expect($user->can('projects.delete'))->toBeTrue()
        ->and(Authkit::currentOrganization()->is($team))->toBeTrue()
        ->and(Feature::store('workos')->active('team-plan'))->toBeTrue();

    $this->getJson('/projects/1')->assertOk(); // auth:workos routes see the user
});
```

What it does, in production terms: the claims flow through the exact same
`AccessTokenClaims` / `WorkosUser::setWorkosClaims()` contract the real guard
uses, so `Gate` checks (ClaimsGateHook), `Authkit::currentOrganization()`,
`$request->organization()`, and the Pennant `workos` store all work
unmodified. As with Laravel's own `actingAs`, the `workos` guard becomes the
default guard for the rest of the test.

Details worth knowing:

- **The user needs a `workos_id`.** A local-only user cannot stand in for a
  WorkOS principal — flags and FGA match scopes against `workos_id`, so
  `actingAs` throws rather than silently answering with no permissions. Pass
  an explicit subject (`['sub' => 'user_123']`) if your model has none.
- **Feature flags default to none.** `feature_flags` is always present in
  the synthetic claims (empty when you don't pass it), so
  `Feature::store('workos')` checks **against the acting principal** resolve
  from claims — false for unclaimed flags, zero HTTP. Known limitation: a
  check for a *different* scope (another user, a non-session organization)
  falls through the driver's claims path to the live WorkOS API — the fakes
  have no feature-flags entry to intercept it.
  `Http::preventStrayRequests()` is the backstop that turns that into a
  loud failure.
- **Call it again to switch context.** A second `actingAs` in one test
  resets the memoized current organization and Pennant's flag cache.
- **Impersonation:** pass
  `'impersonator' => ['id' => 'user_admin', 'email' => ..., 'reason' => ...]`
  to get an impersonated session; the `Impersonating` event fires exactly as
  the real guard would fire it.
- **Extra claims:** unrecognised keys merge into the token payload verbatim
  (`['sid' => 'session_1']`); a raw claim whose name collides with a
  friendly key goes under `'claims' => [...]`, which is applied last and
  always wins.
- **Guests:** `Authkit::actingAsGuest()` installs an explicitly
  unauthenticated guard for proving a route rejects guests.

## `Authkit::fake()`

```php
$fake = Authkit::fake();                    // fake every manager
$fake = Authkit::fake(['fga', 'vault']);    // fake only these — the rest stay real
```

`fake()` binds in-memory fakes into the container and returns the scripting
handle. Your application code does not change: it keeps calling
`Authkit::fga()`, `AuditLog::log()`, `$user->createApiKey()` — the container
now serves the fakes underneath. Unfaked managers keep their real behavior
on every path; asking the handle for one
(`Authkit::fake(['vault'])->fga()`) throws a `LogicException` telling you to
fake it first.

Fake state is per-test automatically — each test boots a fresh application,
and `fake()` creates fresh fakes.

### FGA — `$fake->fga()`

Default is **deny**, matching `WorkosResourcePolicy` semantics: a check you
forgot to script fails loudly on the permission it forgot.

```php
$fake = Authkit::fake(['fga']);

$fake->fga()->allow('projects.view', $project)      // any HasWorkosResource model
    ->deny('projects.delete', $project)
    ->allow('projects.view', '42', 'project');      // or raw external id + type slug

expect(Authkit::check('projects.view', '42', 'project', 'om_1'))->toBeTrue();

$fake->fga()->assertChecked('projects.view', $project);
$fake->fga()->assertNotChecked('projects.delete');
```

With an acting session, the membership id is synthesized (`om_fake_...`) so
you don't need membership projection rows; without acting context, an
omitted membership id throws `MembershipNotResolvedException` exactly like
production. List surfaces are scriptable via
`scriptResourcesForMembership()` / `scriptMembershipsForResource()`.

Known limitation: scripted decisions are keyed by permission + resource
only, not by membership — `allow('projects.view', $project)` answers true
for *every* principal in the test. An "alice can, bob can't" scenario needs
two tests (or assertions on `recordedChecks()`, which do carry the
membership id).

### Invitations — `$fake->invitations()`

A stateful registry: `send()` mints a pending invitation, `revoke()` /
`accept()` transition it, `list()`/`get()`/`findByToken()` read it back.

```php
$fake = Authkit::fake(['invitations']);

$invitation = Authkit::invitations()->send('new@example.com', organizationId: 'org_123', roleSlug: 'member');

Authkit::invitations()->accept($invitation->id);

$fake->invitations()->assertSent('new@example.com', fn ($sent) => $sent->roleSlug === 'member');
$fake->invitations()->assertAccepted($invitation->id);
```

`seed([...])` places fixture invitations without recording a send.

### Audit Logs — `$fake->auditLog()`

`log()` records instead of queueing the wire-call job — but actor resolution
and metadata sanitization stay REAL, so assertions see exactly what
production would have sent (and a missing organization still throws).

```php
Authkit::fake(['audit-log']);

Authkit::actingAs($user, ['organization' => $team]);

AuditLog::log('task.created', [['id' => 'task_1', 'type' => 'task']], ['title' => 'Ship it']);

AuditLog::assertLogged('task.created', fn (array $entry) => $entry['organization_id'] === $team->workos_id
    && $entry['metadata'] === ['title' => 'Ship it']);
```

Exports are captured (`assertExportRequested()`), never polled; drive the
lifecycle with `markExportReady($export->id)` when a test needs a ready
export. Schema registrations are recorded too (`assertSchemaCreated('task.created')`),
and retention is an in-memory map with production validation (30/365).

### Organization sync — `$fake->organizationSync()`

Creating/deleting a `HasWorkosOrganization` model dispatches sync jobs; the
fake captures exactly those two job classes at the Bus level (everything
else dispatches normally).

```php
$fake = Authkit::fake(['organization-sync']);

$team = Team::create(['name' => 'Acme']);          // observer dispatches — captured, no HTTP

$fake->organizationSync()->assertSyncRequested($team);

$fake->organizationSync()->completeSync($team);    // applies the job's local effect: workos_id backfilled

Authkit::actingAs($user, ['organization' => $team]); // now a fully usable acting org
```

If your test also calls `Bus::fake()` itself, order doesn't break the
capture — the assertions always read the *current* Bus fake — but jobs are
recorded by whichever fake was installed at dispatch time, so dispatch and
assert on the same side of your own `Bus::fake()` call.

### API keys — `$fake->apiKeys()`

Covers create/list/revoke for user-scoped AND organization-scoped keys (the
surface emulate has no routes for), plus the `authkit-key` guard's validate
call — a request carrying a fake key authenticates through the REAL guard
machinery.

```php
$fake = Authkit::fake(['api-keys']);

$created = $user->createApiKey('CI key', $team, ['tasks.read']);

$this->getJson('/api/tasks', ['Authorization' => "Bearer {$created->value}"])->assertOk();

$user->revokeApiKey($created->id);

$this->getJson('/api/tasks', ['Authorization' => "Bearer {$created->value}"])->assertUnauthorized();

$fake->apiKeys()->assertCreated(fn (array $key) => $key['permissions'] === ['tasks.read']);
$fake->apiKeys()->assertRevoked($created->id);
```

### Vault — `$fake->vault()`

The KV surface becomes an in-memory map, and — the important half — the
`Vaulted` cast and the `vault` filesystem disk keep running their REAL code
paths over a fake crypto layer.

```php
Authkit::fake(['vault']);

$note = Note::create(['secret' => 'launch codes']);          // Vaulted cast, offline

Storage::disk('vault')->put('contract.pdf', $contents);     // vault disk, offline

Vault::set(['organization_id' => $team->workos_id], 'api-token', 'value');
```

Optimistic locking behaves like production: `update()`/`delete()` with a
stale `versionCheck` throw the SDK's `ConflictException`, so a concurrency
branch is exercisable offline.

> **The fake's output is NOT encryption.** Fake envelopes are marker-prefixed
> base64 (`authkit-fake-vault:v1:...`) — deliberately, visibly not
> ciphertext, so a value that leaks out of a test into a fixture or seeder
> can never masquerade as encrypted data. Never copy fake output anywhere
> production-shaped, and never "borrow" `FakeVaultCrypto` outside tests.

Assert storage is protected without real keys:

```php
expect($rawDatabaseValue)->toStartWith(FakeVaultCrypto::MARKER)
    ->and($rawDatabaseValue)->not->toContain('launch codes');
```

### Pipes — `$fake->pipes()`

Connected accounts are fixtures; tokens are scripted; both named business
exceptions are triggerable.

```php
$fake = Authkit::fake(['pipes']);

$fake->pipes()->connect('user_123', 'github', ['scopes' => ['repo']]);
$fake->pipes()->scriptAccessToken('user_123', 'github', 'gho_test');

expect($user->pipe('github')->accessToken)->toBe('gho_test');

$fake->pipes()->requireReauthorization('user_123', 'github', ['repo:write']);

$user->pipe('github'); // throws PipesReauthorizationRequiredException with a stub redirect URL
```

An unconnected provider throws `PipesAccountNotConnectedException` exactly
like production; `disconnect()` returns an account to that state.

### Groups — `$fake->groups()`

Group CRUD, membership, and role assignments in memory. The production
contract that every role-assignment mutation busts the FGA check cache is
kept — whatever `FgaChecker` the container holds still gets its
`forgetCache()` call.

```php
$fake = Authkit::fake(['groups']);

$group = Authkit::groups()->create($team->workos_id, 'Platform Squad');

Authkit::groups()->addMember($team->workos_id, $group->id, 'om_alice');
Authkit::groups()->assignRole($group->id, 'editor', resourceExternalId: '42', resourceTypeSlug: 'project');

$fake->groups()->assertMemberAdded($group->id, 'om_alice');
$fake->groups()->assertRoleAssigned($group->id, 'editor');
```

## Emulation mode and the fakes

Faking wins over `AUTHKIT_EMULATE_ENABLED=true` by construction: the binding
swap is explicit, and a faked manager's registry-backed operations never
reach the wire regardless of what the environment points at. Leaving
emulation on while faking is allowed — unfaked managers simply keep talking
to whatever host the config names. (One nuance: `Authkit::fake(['api-keys'])`
mirrors the emulate config when building its client, but only so its
*inherited, unfaked* services keep behaving exactly like the real client —
the key operations themselves are answered from the in-memory registry
either way.)
