# Context Map: events-api-worker

**Phase**: 2
**Scout Confidence**: 91/100
**Verdict**: GO

## Dimensions

| Dimension | Score | Notes |
|---|---|---|
| Scope clarity | 20/20 | Spec is a complete rewrite — full implementation code provided inline. Two deliverables: rewrite `src/Commands/EventsListenCommand.php`, create `tests/Feature/EventsListenCommandTest.php`. No ambiguous requirements. ServiceProvider needs no changes (command already registered at line 336). |
| Pattern familiarity | 18/20 | `SyncUsersCommand` cursor loop (`do { ... } while ($cursor !== null)`) is the closest analogue but the new command uses `while (! $this->shouldStop)` instead of a do-while — different enough to avoid copy-paste errors. `Http::fake()` + `Cache::fake()` are established Laravel patterns. `$this->artisan()` assertion pattern is confirmed in `SyncCommandTest`. One gap: signal handling (`pcntl_async_signals`, `pcntl_signal`) has no existing pattern in this codebase; the implementation must be greenfield, but the spec fully specifies it. |
| Dependency awareness | 19/20 | Blast radius is minimal: only `WorkOSServiceProvider` references `EventsListenCommand` (import + registration). The rewrite preserves the class name and namespace so no registration changes are needed. `EventRouting` is already registered as a singleton (Phase 1). `WebhookController::EVENT_MAP` is already public — `processEvent()` can read it directly. `Cache::store($store)` will fall back to default store when `$store` is null (config default), which is correct. `Http::fake()` intercepts `Http::withToken()->acceptJson()->get(...)` chain without issue. |
| Edge case coverage | 17/20 | Spec covers: empty eventTypes (early exit), missing API key (early exit), cursor resume, --since bootstrap, lookback_days fallback, pagination (has `after`), caught-up (empty data), --once flag, error backoff, unknown event type, `unset($since)` after first request. Two gaps: (1) `--once` + error response — the spec says the command exits after the error (spec's error table says "with --once, it exits after the error") but the loop `continue`s and then hits the `--once` break at the top of the next iteration, so it actually retries once before breaking — test assertions should verify carefully; (2) `Cache::store(null)` behavior: when `workos.events.cache_store` is null, `Cache::store(null)` in Laravel returns the default store, which is correct, but the test needs to either not configure it or explicitly set it. |
| Test strategy | 17/20 | `Http::fake()` and `Cache::fake()` are the right fakes. `$this->artisan('workos:events-listen', ['--once' => true])` is the entry point (confirmed by `SyncCommandTest` pattern). `Event::fake()` needed for dispatch assertions. The command uses `sleep()` calls — these will execute in tests unless the command exits before reaching them (via `--once`) or unless mocked. All happy-path and --once tests can avoid `sleep()` by using `--once`. Error path + continuous loop tests need care: `--once` with error hits `continue` then loops back to `--once` break, which means it sleeps for backoff before exiting — tests using error scenarios without `--once` would hang. Spec test list only uses `--once` for the error test case, which should work. |

## Key Patterns

### Phase 1 (retained)
- **Singleton registration** (in `register()`): `$this->app->singleton(ClassName::class, fn() => new ClassName(...config...))` — matches `registerSessionManager()` exactly
- **`Event::fake()` + `Event::assertDispatched()`**: established in `WebhookTest`
- **`$this->mock(Webhook::class, ...)` in feature tests**: used in all `WebhookTest` cases
- **`private const array`** (PHP 8.3 typed constant): already used in `WebhookController` for `EVENT_MAP`
- **Constructor property promotion with `readonly`**: used in `SessionManager`, `EnvironmentDetector`

### Phase 2 (new)
- **`Http::fake([...])` + `Http::assertSent(...)`**: intercepts `Http::withToken()->acceptJson()->get()` chain — established Laravel testing pattern, not yet used in this codebase but standard
- **`Cache::fake()` / `Cache::store()->put()`**: cursor persistence testing — standard Laravel, no special setup needed
- **`$this->artisan('workos:events-listen', ['--once' => true])->assertSuccessful()`**: command test entry point, matches `SyncCommandTest` style
- **Cursor loop pattern**: `while (! $this->shouldStop)` with `break` on `--once`, vs `SyncUsersCommand`'s `do { } while ($cursor)` — similar concept, different control flow
- **`Http::withToken($apiKey)->acceptJson()->get($url, $params)`**: replaces existing `Http::withHeaders([...])->withOptions([...])->get()` SSE pattern — both are Laravel Http facade

## Dependencies

### Phase 1 (retained)
- `EventRouting` → `WebhookController::EVENT_MAP` (cross-layer import: `Support` → `Http\Controllers`)
- `WorkOSServiceProvider::register()` → `EventRouting` (singleton)
- `WebhookController` → `EventRouting` (injected via constructor)

### Phase 2 (new)
- `EventsListenCommand::handle(EventRouting $routing)` → `EventRouting` (auto-injected by Laravel command DI)
- `EventsListenCommand` → `Illuminate\Support\Facades\Cache` (cursor persistence)
- `EventsListenCommand` → `Illuminate\Support\Facades\Http` (REST polling)
- `EventsListenCommand` → `Carbon\CarbonImmutable` (lookback date calculation)
- `EventsListenCommand` → `WebhookController::EVENT_MAP` (typed event dispatch in `processEvent()`)
- `EventsListenCommand` → `WebhookReceived` event class (generic dispatch)
- `WorkOSServiceProvider` → `EventsListenCommand` (already registered — no change needed)
- Config keys read: `workos.api_key`, `workos.events.cache_key`, `workos.events.cache_store`, `workos.events.poll_interval`, `workos.events.limit`, `workos.events.lookback_days`

## Conventions

- File header: `<?php` + blank line + `declare(strict_types=1);` + blank line + `namespace ...;`
- Class in `src/Commands/` → namespace `WorkOS\AuthKit\Commands`
- Test files: Pest function-based (no class wrapper), uses `TestCase` via `uses(TestCase::class)->in('Feature')` in `Pest.php`
- All properties/constructor params typed; readonly where immutable
- PHPStan level 8: `@var`, `@param`, `@return` annotations on complex types
- `config()` calls use dot-notation with typed casts where needed (e.g., `(int) config(...)`)
- `self::FAILURE` / `self::SUCCESS` return codes (not raw 0/1)
- `$this->error()`, `$this->warn()`, `$this->info()`, `$this->line()` for console output
- Private class properties declared with `private bool $shouldStop = false` (no readonly for mutable state)
- `\Illuminate\Contracts\Cache\Repository` return type on `cacheStore()` — fully qualified in docblock or import needed for PHPStan level 8

## Risks

### Phase 1 (retained)
1. **PHPStan `array_filter` with `ARRAY_FILTER_USE_BOTH`**: Callback type must satisfy level 8 inference — already in `EventRouting`, not new work
2. **~~`user.session_revoked` category mismatch~~**: Fixed — correct event name is `session.revoked` per WorkOS docs
3. **Workbench config divergence**: `workbench/config/workos.php` still has `sync_enabled` — won't affect tests but workbench app will silently ignore it

### Phase 2 (new)
4. **`--once` + error path test hanging**: Error recovery path calls `sleep(min($pollInterval * 2, 30))` then `continue`, then hits `--once` break at top of next iteration — but the sleep fires first in tests. Tests covering error + --once should use `Http::fake()` with a sequence (error response followed by empty response) or accept that `sleep()` fires in test context. Alternatively, verify the spec intent: the error case test should use `--once` and not get stuck because the sleep is `min(5*2, 30) = 10s` which will execute before the break. **Mitigation**: use very short poll_interval in test config (`config(['workos.events.poll_interval' => 0])`).
5. **`Cache::fake()` isolation**: `Cache::fake()` replaces the cache manager globally — tests that check `Cache::store($store)->get($key)` must ensure fake is active. The `cacheStore()` method calls `Cache::store($store)` where `$store` may be null — `Cache::fake()` handles this correctly.
6. **PHPStan `\Illuminate\Contracts\Cache\Repository` return type**: `cacheStore()` returns this interface. The `Cache::store()` method returns `\Illuminate\Contracts\Cache\Repository`, so the return type annotation is exact — no PHPStan issue expected, but the import must be added to the file.
7. **`processEvent()` docblock**: The method receives `array $event` — PHPStan level 8 requires `@param array<string, mixed> $event` annotation since `mixed` values are in play. The current `EventsListenCommand` already has this annotation; the rewrite must preserve it.
8. **`CarbonImmutable` import**: The rewrite adds `use Carbon\CarbonImmutable;` — Carbon is a transitive Laravel dependency, present in this project, but it's a new import for this command file.
