# Implementation Spec: WorkOS SDK v5 Migration - Phase 3

**Contract**: ./contract.md
**Estimated Effort**: L

## Technical Approach

Phase 3 migrates all controllers, commands, and webhook handling to v5 patterns. This is the broadest phase — it touches every file that calls SDK methods with v4 names or handles v4 response shapes.

Key changes:
- **WebhookController**: v4 `constructEvent()` returns a string on failure. v5's `webhookVerification()->verifyEvent()` throws `InvalidArgumentException` on failure — switch from return-value checking to exception-based flow.
- **OrganizationController**: `sendInvitation()` → `createInvitations()`, `getInvitation()` → renamed, `revokeInvitation()` → renamed
- **SyncUsersCommand**: v4 pagination destructuring (`[$before, $after, $users]`) → v5 `PaginatedResponse->data` / `autoPagingIterator()`
- **AuditLogger**: `createEvent()` → `createEvents()`
- **EventsListenCommand**: Replace manual `Http::` calls with `$workos->events()->listEvents()`
- **Widget token**: `Widgets::getToken()` → `$workos->widgets()->createToken()`

## Feedback Strategy

**Inner-loop command**: `composer test -- --filter=Webhook --filter=Organization --filter=SyncUsers --filter=Audit`

**Playground**: Pest test suite — all changes are to controllers/commands with existing test coverage.

**Why this approach**: Every file in this phase has corresponding tests. Running targeted test filters gives immediate feedback on whether the v5 method signatures and response handling are correct.

## File Changes

### Modified Files

| File Path | Changes |
|---|---|
| `src/Http/Controllers/WebhookController.php` | Replace `Webhook::constructEvent()` return-value check with v5 exception-based `webhookVerification()->verifyEvent()` |
| `src/Http/Controllers/OrganizationController.php` | Rename `sendInvitation()` → `createInvitations()`, `getInvitation()` → v5 equivalent, `revokeInvitation()` → v5 equivalent |
| `src/Commands/SyncUsersCommand.php` | Replace v4 pagination destructuring with v5 `PaginatedResponse->data` or `autoPagingIterator()` |
| `src/Commands/EventsListenCommand.php` | Replace manual `Http::withToken()->get('https://api.workos.com/events')` with `$workos->events()->listEvents()` |
| `src/Audit/AuditLogger.php` | Replace `createEvent()` → `createEvents()`, update import from `\WorkOS\AuditLogs` to use the v5 client accessor |
| `src/Livewire/Concerns/WithWidgetToken.php` | Replace `Widgets::getToken()` → v5 `widgets()->createToken()` |
| `src/Livewire/Concerns/WithWidgetApi.php` | Update raw Guzzle calls if v5 provides widget data endpoints |
| `src/FeatureFlags/FeatureFlagService.php` | Update `Organizations::listOrganizationFeatureFlags()` → v5 equivalent (may be `$workos->featureFlags()` or `$workos->organizations()->...`) |
| Tests for all above files | Update mocks, assertions, and response shapes for v5 |

## Implementation Details

### 1. WebhookController — Exception-Based Verification

**Pattern to follow**: `src/Http/Controllers/WebhookController.php:83-124`

**Overview**: v4 returns a string error on signature failure. v5 throws `InvalidArgumentException`. The controller must catch the exception instead of checking the return value.

**Implementation steps**:

1. Update constructor — replace `Webhook` with the v5 client's webhook verification service:
   ```php
   public function __construct(
       private readonly EventRouting $routing,
   ) {}
   ```

2. Update `handle()`:
   ```php
   public function handle(Request $request): Response
   {
       $payload = $request->getContent();
       $signature = $request->header('WorkOS-Signature', '');
       $secret = config('workos.webhook_secret', '');

       if (empty($secret)) {
           return response('Webhook secret not configured', 500);
       }

       if (empty($signature)) {
           return response('Invalid signature', 400);
       }

       try {
           // v5: throws InvalidArgumentException on failure
           app('workos')->webhookVerification()->verifyEvent(
               eventBody: $payload,
               eventSignature: $signature,
               secret: $secret,
           );
       } catch (\InvalidArgumentException) {
           return response('Invalid signature', 400);
       }

       // Parse event data (unchanged)
       $event = json_decode($payload, true);
       $eventType = $event['event'];
       $eventData = $event['data'];

       event(new WorkOSEventReceived($eventType, $eventData));

       $eventClass = self::EVENT_MAP[$eventType] ?? null;
       if ($eventClass !== null && $this->routing->shouldProcessVia($eventType, 'webhooks')) {
           event(new $eventClass($eventData));
       }

       return response('OK', 200);
   }
   ```

3. Remove `use WorkOS\Webhook;` import
4. Update `WorkOSServiceProvider` — remove `Webhook` injection from the controller if it was resolved from the container

**Key decisions**:
- We resolve the webhook verification service via `app('workos')` rather than constructor injection because the controller no longer needs a dedicated `Webhook` instance
- The `EVENT_MAP` constant is unchanged — webhook event type strings haven't changed in v5

**Feedback loop**:
- **Playground**: Webhook controller tests
- **Experiment**: Test with valid signature, invalid signature, missing signature, expired timestamp
- **Check command**: `composer test -- --filter=Webhook`

### 2. OrganizationController — Method Renames

**Pattern to follow**: `src/Http/Controllers/OrganizationController.php`

**Overview**: v5 renames invitation methods. Update all call sites.

**Implementation steps**:

1. `sendInvitation()` → `createInvitations()`:
   ```php
   // v4: WorkOS::userManagement()->sendInvitation(email: $email, organizationId: $orgId)
   // v5: WorkOS::userManagement()->createInvitations(email: $email, organizationId: $orgId)
   ```

2. `getInvitation()` → v5 equivalent (check v5 method name)

3. `revokeInvitation()` → v5 equivalent

4. Update any response handling — v5 returns typed resources, not arrays

**Feedback loop**:
- **Playground**: Organization controller tests
- **Experiment**: Test invite send, invite revoke
- **Check command**: `composer test -- --filter=Organization`

### 3. SyncUsersCommand — Pagination Migration

**Pattern to follow**: `src/Commands/SyncUsersCommand.php`

**Overview**: v4 uses destructured list returns (`[$before, $after, $users]`). v5 uses `PaginatedResponse` with `->data`, `->listMetadata`, and `->autoPagingIterator()`.

**Implementation steps**:

1. Replace pagination loop:
   ```php
   // v4:
   // [$before, $after, $users] = $userManagement->listUsers(after: $cursor);
   // v5:
   $page = app('workos')->userManagement()->listUsers(after: $cursor);
   foreach ($page->data as $user) {
       // sync user...
   }
   $cursor = $page->listMetadata['after'] ?? null;
   // Or use auto-pagination:
   foreach (app('workos')->userManagement()->listUsers()->autoPagingIterator() as $user) {
       // sync user...
   }
   ```

2. Prefer `autoPagingIterator()` for the sync command — it handles cursor management automatically

3. Update `User` resource access — v5 resources use typed properties, not array access

**Feedback loop**:
- **Playground**: SyncUsers command tests
- **Experiment**: Test with 0 users, 1 user, multi-page results
- **Check command**: `composer test -- --filter=SyncUsers`

### 4. AuditLogger — Method Rename

**Pattern to follow**: `src/Audit/AuditLogger.php`

**Overview**: Simple method rename from `createEvent()` to `createEvents()`.

**Implementation steps**:

1. Update the `log()` method:
   ```php
   // v4: $this->auditLogs->createEvent(...)
   // v5: app('workos')->auditLogs()->createEvents(...)
   ```

2. Remove the `AuditLogs` constructor dependency — use the v5 client via the service container

3. Update constructor:
   ```php
   public function __construct(
       private readonly SessionManager $session,
   ) {}
   ```

4. Update the `createEvents()` call arguments for v5 named parameters

### 5. EventsListenCommand — Replace Manual HTTP

**Pattern to follow**: `src/Commands/EventsListenCommand.php`

**Overview**: Replace `Http::withToken($apiKey)->get('https://api.workos.com/events', ...)` with `$workos->events()->listEvents(...)`.

**Implementation steps**:

1. Replace the HTTP call:
   ```php
   // v4: Http::withToken($apiKey)->get('https://api.workos.com/events', $params)
   // v5: app('workos')->events()->listEvents(after: $cursor, ...)
   ```

2. Handle v5 response shape — `PaginatedResponse` instead of raw JSON

3. Update cursor-based pagination to use v5's `listMetadata['after']`

4. Remove `use Illuminate\Support\Facades\Http;` import and manual token/URL construction

**Feedback loop**:
- **Playground**: EventsListen command tests
- **Experiment**: Test event polling with mock responses
- **Check command**: `composer test -- --filter=EventsListen`

### 6. FeatureFlagService Update

**Pattern to follow**: `src/FeatureFlags/FeatureFlagService.php`

**Overview**: Replace `Organizations::listOrganizationFeatureFlags()` with v5's native `featureFlags()` service or the renamed method.

**Implementation steps**:

1. Check if v5 has `$workos->featureFlags()->...` methods that replace the org-based flag lookup
2. Update the service to use the v5 client instead of directly constructing `Organizations`
3. Update constructor to remove `Organizations` dependency

### 7. Widget Token Migration

**Pattern to follow**: `src/Livewire/Concerns/WithWidgetToken.php`

**Overview**: `Widgets::getToken()` → `widgets()->createToken()` in v5. Return type changes to `WidgetSessionTokenResponse`.

**Implementation steps**:

1. Replace `Widgets::getToken()` with v5 call:
   ```php
   // v4: (new Widgets)->getToken(...)
   // v5: app('workos')->widgets()->createToken(...)
   ```
2. Extract the token string from the v5 response object

## Error Handling

| Error Scenario | Handling Strategy |
|---|---|
| Webhook signature invalid | `InvalidArgumentException` caught, return 400 (was string check, now exception) |
| createInvitations fails | Let the exception bubble — controller handles via try/catch |
| autoPagingIterator network error | SyncUsersCommand catches and logs — same as v4 |
| Events API returns empty | Command exits gracefully — same as v4 |

## Failure Modes

| Component | Failure Mode | Trigger | Impact | Mitigation |
|---|---|---|---|---|
| WebhookController | v5 verifyEvent signature mismatch | Wrong parameter names | All webhooks rejected | Test with real WorkOS webhook payloads |
| SyncUsersCommand | autoPagingIterator infinite loop | v5 doesn't terminate iteration | Command hangs | Use manual pagination with cursor nil-check as fallback |
| EventsListenCommand | v5 events() method doesn't match expected params | API params renamed in v5 | Command fails to poll events | Check v5 SDK source for exact parameter names |
| AuditLogger | createEvents() parameter rename | v5 uses different named params | Audit logging silently fails | Catch exception in AuditLogger (existing pattern) |

## Validation Commands

```bash
# Targeted tests
composer test -- --filter=Webhook
composer test -- --filter=Organization
composer test -- --filter=SyncUsers
composer test -- --filter=Audit
composer test -- --filter=EventsListen

# Static analysis
composer analyse

# Full test suite
composer test
```

---

_This spec is ready for implementation. Follow the patterns and validate at each step._
