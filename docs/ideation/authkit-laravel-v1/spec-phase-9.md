# Phase 9 — Vault

**Follow `spec-template-feature-area.md`; inputs below.** This delta does not repeat template content (Shared Technical Approach, Shared Conventions, Test-Path Selection, Shared Feedback Strategy, Standard Validation Commands, Shared Failure-Mode Prompts, Rollout) — only phase-specific inputs, designs, and deviations.

## 1. Phase Header

- **Phase:** 9 — Vault
- **Risk:** medium (per contract execution graph)
- **Blocking:** no
- **Prereqs:** Foundation & Client Binding (Phase 1) only — **not** Organizations & Org Context. This is a deliberate, verified fact: unlike API Keys (`createOrganizationApiKey`) and Connect (`createM2MApplication`), no Vault SDK method takes a required `organizationId` parameter — `createDataKey`, `createKv`, etc. all take a free-form `context: array<string, string>` instead (see §4, Component 1). Org-awareness in Vault is opt-in and duck-typed, not a hard SDK dependency, which is why Vault's prereq graph stays minimal even though it deals with org-scoped isolation.
- **Estimated effort:** **L**. Four non-trivial surfaces (key-context resolution, Eloquent cast, a full `Illuminate\Contracts\Filesystem\Filesystem` decorator, a 7-method KV facade), each requiring correctness-sensitive crypto/round-trip tests, with zero emulate coverage to lean on (100% of tests are hand-built MockHandler fixtures).
- **Scope closure:** the contract's `scope.full` (Depth Extensions, Phase 12) has no Vault line item — Invitations, JWT templates/CORS, Groups API, and FGA resource-graph/caching are the only Full-tier items, and none touch Vault. **Vault's scope is fully closed by this phase** — no later phase revisits it.

## 2. Scope Rows Implemented

Verbatim from the approved contract (`scope.mvp`):

> **Vault**: Vaulted Eloquent cast (attribute envelope encryption); vault filesystem driver wrapping any disk with BYOK data-key encryption; Vault facade for KV.
> — *Reason: Attribute + file + KV are the three storage shapes the brain dump explicitly requested; each carries a success criterion.*

Relevant success criteria (verbatim from `successCriteria`):

- *"Vault usable core works end-to-end: Vaulted cast round-trips a model attribute, the vault filesystem driver round-trips a file on a wrapped disk, and the KV facade CRUDs a secret — envelope-encryption asserted against MockHandler fakes (emulate has zero Vault coverage)"* — check: `vendor/bin/pest --filter=Vault` exits 0.
- Contributes to: *"Every scope area has a dedicated Pest feature suite — emulate-backed where covered ... and MockHandler-backed where not (Vault, ...)"* — check: `ls tests/Feature/*Test.php | wc -l` ≥ 16.
- Contributes to: *"Idiom coverage: each promised Laravel mechanism exists and is registered"* (Vaulted cast, vault filesystem driver — both named explicitly in the contract's idiom list).

## 3. Decisions Considered and Rejected

Directly relevant decisions carried from the contract's decision log:

| Decision | Rejected | Reason | Relevance to Vault |
|---|---|---|---|
| Credentials read from config only; `env()` never read outside config files | Runtime `env()` reads like the SDK's own fallback does | `php artisan config:cache` empties env at runtime (laravel/framework#55028 class of bug) | Vault code never constructs `new \WorkOS\WorkOS(...)` itself outside tests — it resolves the client Phase 1 bound from config. No `env()` call appears anywhere in `src/Vault`, `src/Casts`, or `src/Filesystem`. |
| Truth bar: emulate-backed Pest tests in CI, Guzzle MockHandler fakes only where emulate lacks coverage (Vault 0%, audit export, user-scoped API keys, flags verb-mismatch, Connect/MCP, Pipes) | SDK fakes only | Wire fidelity where possible; emulate v0.6.0 covers ~62% of endpoints, Vault is in the **zero** bucket | This is the phase's entire test-path story: **every** Vault test is MockHandler-backed. There is no emulate fallback to lean on for wire-format drift protection (see Failure Modes §8). |
| Local Eloquent rows are declared projections (user, org, domains, memberships) refreshed by the events pipeline; no other WorkOS-shaped state duplicated locally | No local state / read-through API calls per request | Laravel's ecosystem assumes Eloquent models; WorkOS best practice is local state kept fresh by events | **Vault does not create a new projection.** The ciphertext envelope stored in the app's own text column is opaque locally-generated encrypted data, not a copy of WorkOS-held state — WorkOS never durably stores the plaintext or the envelope, only the wrapped data key. The `ProjectionBoundary` arch test's whitelist (user/org/domain/membership) is untouched by this phase; a vaulted column on an arbitrary app model is not a new whitelist entry. |
| v1 targets the Full tier: MVP's 16 areas plus 5 depth extensions, folded into a dedicated Depth Extensions phase | MVP-only v1 with depth extensions deferred | Stakeholder tier selection at contract approval | Confirms Vault ships at usable-core depth *in this phase* and is not revisited — the Depth Extensions phase (12) has no Vault-related item. |
| Stay on Pest 4 with PHP ^8.3 floor | Pest 5 (requires PHP 8.4+) | PHP 8.3 supported until Dec 2027; Laravel 13 supports it | All test file conventions below use Pest 4 `describe()`/`it()` syntax — no Pest 5 constructs. |

The remaining contract decisions were reviewed and found **not applicable** to this phase's content (they govern auth-core sessions, RBAC/FGA claim semantics, org context, events sidecar, webhooks, directory sync, feature flags, `php artisan dev` wiring, the API Keys/Connect org-ID prereq correction, Widgets exclusion, the token audit, quickstart criteria, and the express-run process choice) — none of these mechanisms are touched by Vault's cast, filesystem driver, or KV facade.

## 4. Components

### Component 1 — Vault Key-Context Resolution

**Laravel mechanism:** a small resolver contract + default implementation, container-bound and config-swappable — the idiom Laravel itself uses for pluggable policy objects (cf. `Illuminate\Contracts\Hashing\Hasher` swap points).

**SDK methods wrapped:** none directly — this component only *produces* the `context: array<string, string>` argument that Component 2 passes into `WorkOS\Service\Vault::encrypt()`.

**Why this exists as its own component:** the phase brief is explicit that `key_context` derivation must be configurable, and that getting it wrong is a silent, non-crashing failure — not a decryption failure (see §8, "Key-context drift"). It deserves its own interface, its own default, and its own fast, network-free test.

**Key design:**

```php
namespace Authkit\Authkit\Vault;

use Illuminate\Database\Eloquent\Model;

interface ResolvesVaultKeyContext
{
    /**
     * @return array<string, string>
     */
    public function resolve(Model $model, string $attribute): array;
}
```

```php
namespace Authkit\Authkit\Vault;

use Illuminate\Database\Eloquent\Model;

final class DefaultVaultKeyContextResolver implements ResolvesVaultKeyContext
{
    public function resolve(Model $model, string $attribute): array
    {
        $context = [
            'model' => $model::class,
            'attribute' => $attribute,
        ];

        // Full override hook: a model can take complete control of its own context.
        if (method_exists($model, 'vaultKeyContext')) {
            return array_merge($context, $model->vaultKeyContext($attribute));
        }

        // Org-awareness hook, duck-typed rather than imported from Phase 3's
        // HasWorkosOrganization trait: Vault's only prereq is Phase 1, so a
        // Phase-3 class dependency is not guaranteed to exist yet. Any model
        // that exposes workosOrganizationId(): ?string gets automatic org
        // isolation with no wiring required.
        if (method_exists($model, 'workosOrganizationId')) {
            $organizationId = $model->workosOrganizationId();
            if ($organizationId !== null) {
                $context['organization_id'] = $organizationId;
            }
        }

        return $context;
    }
}
```

Default context = `['model' => ..., 'attribute' => ...]`, augmented with `organization_id` when the model exposes it, with a full-override escape hatch via `vaultKeyContext(string $attribute): array` on the model itself. Swappable globally via `config('authkit.vault.key_context_resolver')`.

**Implementation steps:**

1. `vendor/bin/testbench make:interface ResolvesVaultKeyContext` — generates into `workbench/app/Interfaces/ResolvesVaultKeyContext.php` (namespace `Workbench\App\Interfaces`). Move to `src/Vault/ResolvesVaultKeyContext.php`, change namespace to `Authkit\Authkit\Vault`, apply the signature above.
2. `vendor/bin/testbench make:class DefaultVaultKeyContextResolver` — generates into `workbench/app/DefaultVaultKeyContextResolver.php`. Move to `src/Vault/DefaultVaultKeyContextResolver.php`, namespace `Authkit\Authkit\Vault`, implement as above.
3. `vendor/bin/testbench make:exception InvalidVaultKeyContextResolverException` → move to `src/Exceptions/InvalidVaultKeyContextResolverException.php`, namespace `Authkit\Authkit\Exceptions`, extend `\RuntimeException`, add the static `forConfiguredClass()` factory (see §6).
4. Bind in `AuthkitServiceProvider::register()`, wrapped in the try/catch that throws `InvalidVaultKeyContextResolverException` on a bad config value (see §6).

**Feedback loop:**

- **Inner-loop command:** `vendor/bin/pest --filter=VaultKeyContext` — pure PHP, zero I/O, runs in milliseconds.
- **Playground:** none needed beyond Pest itself — there is no network call to fake here.
- **Parameterized experiment:** a Pest dataset over four cases: (a) bare model with neither hook → base context only; (b) model with `workosOrganizationId()` returning a string → `organization_id` present; (c) model with `workosOrganizationId()` returning `null` → key omitted entirely (never sent as `null`); (d) model with `vaultKeyContext()` override → override wins, base keys still present unless the override itself omits them.
- **Check:** `vendor/bin/pest --filter=VaultKeyContext` exits 0.

### Component 2 — Vaulted Eloquent Cast

**Laravel mechanism:** a custom cast implementing `Illuminate\Contracts\Database\Eloquent\CastsAttributes`.

**SDK methods wrapped:** `WorkOS\Service\Vault::encrypt(string $data, array $context, ?string $associatedData = null): string` and `WorkOS\Service\Vault::decrypt(string $encryptedData, ?string $associatedData = null): string` (hand-maintained client-side AES-256-GCM, `vendor/workos/workos-php/lib/Service/Vault.php:302-472`). These are **not** HTTP-response DTOs — they are pure local crypto that make exactly one network call each (`createDataKey` on encrypt, `createDecrypt` on decrypt) to fetch/unwrap a data key, then do the AES-GCM work in PHP with `openssl_encrypt`/`openssl_decrypt`.

**Key design:**

```php
namespace Authkit\Authkit\Vault;

use Illuminate\Database\Eloquent\Model;
use WorkOS\WorkOS;

final class VaultCrypto
{
    public function __construct(
        private readonly WorkOS $client,
        private readonly ResolvesVaultKeyContext $contextResolver,
    ) {
    }

    public function encryptAttribute(Model $model, string $attribute, string $plaintext): string
    {
        return $this->client->vault()->encrypt(
            $plaintext,
            $this->contextResolver->resolve($model, $attribute),
        );
    }

    /**
     * Shared decrypt path for both the Eloquent cast and the filesystem driver —
     * decrypt() takes no context: the wrapped data-key blob embedded in the
     * envelope is self-describing server-side. See §8, "Key-context drift".
     */
    public function decrypt(string $envelope): string
    {
        return $this->client->vault()->decrypt($envelope);
    }

    /**
     * Entry point for callers that already have a concrete context array and
     * are not resolving it from an Eloquent model (the filesystem driver).
     *
     * @param array<string, string> $context
     */
    public function encryptWithContext(string $plaintext, array $context): string
    {
        return $this->client->vault()->encrypt($plaintext, $context);
    }
}
```

```php
namespace Authkit\Authkit\Casts;

use Authkit\Authkit\Vault\VaultCrypto;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Envelope-encrypts a single Eloquent attribute through WorkOS Vault's
 * client-side data-key encryption.
 *
 * NOT SEARCHABLE OR QUERYABLE. Every encrypt() call mints a fresh data key
 * and a fresh random IV, so the stored ciphertext is non-deterministic —
 * two saves of the identical plaintext produce different bytes. Do not:
 *   - WHERE this column, LIKE this column, or add a unique index on it
 *   - Expect re-saving the same value to no-op at the storage layer
 * If you need to look records up by a secret value, maintain a separate
 * deterministic hash column outside of this cast.
 *
 * The backing column MUST be TEXT/LONGTEXT, never VARCHAR. Truncation
 * corrupts the envelope's AES-GCM tag and surfaces as a decrypt-time
 * RuntimeException, not a save-time error — see the phase spec, §8.
 */
final class Vaulted implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return app(VaultCrypto::class)->decrypt($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return app(VaultCrypto::class)->encryptAttribute($model, $key, (string) $value);
    }
}
```

Null-safety: both directions short-circuit on `null` only (an empty string `""` is a legitimate plaintext and is encrypted normally — it is not conflated with "absent").

**Implementation steps:**

1. `vendor/bin/testbench make:class VaultCrypto` → move generated stub to `src/Vault/VaultCrypto.php`, namespace `Authkit\Authkit\Vault`, implement as above.
2. `vendor/bin/testbench make:cast Vaulted` → generates `workbench/app/Casts/Vaulted.php` (namespace `Workbench\App\Casts`) with the `CastsAttributes` skeleton. Move to `src/Casts/Vaulted.php`, change namespace to `Authkit\Authkit\Casts`, implement as above.
3. `vendor/bin/testbench make:migration create_vault_demo_records_table` → lands directly in `workbench/database/migrations/` (confirmed via `testbench.yaml`'s `migrations: [workbench/database/migrations]` — no relocation needed, unlike the cast/class generators). Define an anonymous migration creating `vault_demo_records` with a **`text()`** (not `string()`) `secret` column, nullable.
4. `vendor/bin/testbench make:model VaultDemoRecord` → generates `workbench/app/Models/VaultDemoRecord.php`. Add `protected function casts(): array { return ['secret' => \Authkit\Authkit\Casts\Vaulted::class]; }` (modern method-based casts, matching the existing `workbench/app/Models/User.php` convention — not the legacy `protected $casts` property).
5. Wire the `VaultCrypto` singleton and `ResolvesVaultKeyContext` binding in the service provider (§6).

**Feedback loop:**

- **Inner-loop command:** `vendor/bin/pest --filter=Vault` (seconds — MockHandler, no real network).
- **Playground:** a Pest feature test against `Workbench\App\Models\VaultDemoRecord`, backed by the MockHandler round-trip pattern below (§7).
- **Parameterized experiment:** vary plaintext (empty string, unicode, ~4KB blob) and context (bare model vs. model with `workosOrganizationId()`) across the same round-trip assertion.
- **Check:** `vendor/bin/pest --filter=Vault` exits 0.

### Component 3 — Vault Filesystem Driver

**Laravel mechanism:** `Illuminate\Support\Facades\Storage::extend('vault', Closure)` — verified against the vendored Laravel 12 `FilesystemManager::resolve()`/`callCustomCreator()`: the closure receives `($app, array $config)` and must return anything implementing `Illuminate\Contracts\Filesystem\Filesystem` (Laravel 9+ accepts a contract implementation directly — no Flysystem adapter object required). This is a **decorator**, not a new storage backend: it wraps whatever disk `$config['disk']` names.

**SDK methods wrapped:** the same `Vault::encrypt()` / `Vault::decrypt()` pair as Component 2, via the shared `VaultCrypto::encryptWithContext()` / `decrypt()` methods — one fresh data key per file write (encrypt() always calls `createDataKey` regardless of context repetition).

**Key design:**

```php
namespace Authkit\Authkit\Filesystem;

use Authkit\Authkit\Exceptions\VaultFileTooLargeException;
use Authkit\Authkit\Vault\VaultCrypto;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;

final class VaultFilesystemAdapter implements Filesystem
{
    public function __construct(
        private readonly Filesystem $inner,
        private readonly VaultCrypto $crypto,
        private readonly array $context,
        private readonly int $maxEncryptBytes,
    ) {
    }

    public function get($path)
    {
        $envelope = $this->inner->get($path);

        return $envelope === null ? null : $this->crypto->decrypt($envelope);
    }

    public function put($path, $contents, $options = [])
    {
        $plaintext = $this->normalizeContents($contents);

        if (strlen($plaintext) > $this->maxEncryptBytes) {
            throw VaultFileTooLargeException::forPath($path, strlen($plaintext), $this->maxEncryptBytes);
        }

        $envelope = $this->crypto->encryptWithContext($plaintext, $this->context);

        return $this->inner->put($path, $envelope, $options);
    }

    public function putFile($path, $file = null, $options = [])
    {
        if (is_null($file) || is_array($file)) {
            [$path, $file, $options] = ['', $path, $file ?? []];
        }

        $file = is_string($file) ? new File($file) : $file;

        return $this->putFileAs($path, $file, $file->hashName(), $options);
    }

    public function putFileAs($path, $file, $name = null, $options = [])
    {
        if (is_null($name) || is_array($name)) {
            [$path, $file, $name, $options] = ['', $path, $file, $name ?? []];
        }

        $stream = fopen(is_string($file) ? $file : $file->getRealPath(), 'r');
        $storedPath = trim($path.'/'.$name, '/');

        $result = $this->put($storedPath, $stream, $options);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $result ? $storedPath : false;
    }

    public function writeStream($path, $resource, array $options = [])
    {
        return $this->put($path, stream_get_contents($resource) ?: '', $options);
    }

    public function readStream($path)
    {
        $plaintext = $this->get($path);

        if ($plaintext === null) {
            return null;
        }

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $plaintext);
        rewind($stream);

        return $stream;
    }

    public function prepend($path, $data)
    {
        return $this->put($path, $data.PHP_EOL.($this->get($path) ?? ''));
    }

    public function append($path, $data)
    {
        return $this->put($path, ($this->get($path) ?? '').PHP_EOL.$data);
    }

    // Pure passthrough — encryption is transparent to these:
    public function path($path) { return $this->inner->path($path); }
    public function exists($path) { return $this->inner->exists($path); }
    public function delete($paths) { return $this->inner->delete($paths); }
    public function copy($from, $to) { return $this->inner->copy($from, $to); }
    public function move($from, $to) { return $this->inner->move($from, $to); }
    public function size($path) { return $this->inner->size($path); } // ENCRYPTED size — see §8
    public function lastModified($path) { return $this->inner->lastModified($path); }
    public function getVisibility($path) { return $this->inner->getVisibility($path); }
    public function setVisibility($path, $visibility) { return $this->inner->setVisibility($path, $visibility); }
    public function files($directory = null, $recursive = false) { return $this->inner->files($directory, $recursive); }
    public function allFiles($directory = null) { return $this->inner->allFiles($directory); }
    public function directories($directory = null, $recursive = false) { return $this->inner->directories($directory, $recursive); }
    public function allDirectories($directory = null) { return $this->inner->allDirectories($directory); }
    public function makeDirectory($path) { return $this->inner->makeDirectory($path); }
    public function deleteDirectory($directory) { return $this->inner->deleteDirectory($directory); }

    private function normalizeContents(mixed $contents): string
    {
        return match (true) {
            is_string($contents) => $contents,
            is_resource($contents) => stream_get_contents($contents) ?: '',
            $contents instanceof \Psr\Http\Message\StreamInterface => (string) $contents,
            $contents instanceof \Illuminate\Http\File,
            $contents instanceof \Illuminate\Http\UploadedFile => file_get_contents($contents->getRealPath()) ?: '',
            default => throw new \InvalidArgumentException(
                'Unsupported contents type for the vault filesystem driver.'
            ),
        };
    }
}
```

`putFile`/`putFileAs` deliberately mirror `Illuminate\Filesystem\FilesystemAdapter`'s own normalization (verified against the vendored Laravel 12 source) so that stream-based uploads behave identically to the stock local driver — the only difference is that the final `put()` call goes through our encrypt path instead of straight to Flysystem.

**Memory profile (explicit, not hidden):** there is no streaming encryption. `get()`/`put()`/`readStream()`/`writeStream()` all fully materialize plaintext in PHP memory — the SDK's `encrypt()`/`decrypt()` operate on a single string via `openssl_encrypt`/`openssl_decrypt`, with no chunked-cipher API exposed. Peak memory during a `put()` is roughly 3–4× the plaintext size (plaintext buffer + base64-encoded envelope buffer + whatever the underlying disk driver buffers internally). **A configurable size guard replaces pretending to stream:**

```php
'filesystem' => [
    'max_encrypt_bytes' => 10 * 1024 * 1024, // 10 MiB default
],
```

10 MiB is chosen so that even a 3–4× peak stays comfortably under any realistic PHP `memory_limit` (128M+), while covering the realistic target use case (documents, certs, small exports) — large media belongs on a plain disk with app-level access control, not an application-layer envelope-encrypted one. Configurable globally via `authkit.vault.filesystem.max_encrypt_bytes` or per-disk via the disk's own `max_encrypt_bytes` key. **The guard is checked before any network call** — an oversized file never triggers a wasted `createDataKey` round trip (see §7 test).

**Implementation steps:**

1. `vendor/bin/testbench make:exception VaultFileTooLargeException` → move to `src/Exceptions/VaultFileTooLargeException.php`, namespace `Authkit\Authkit\Exceptions`, extend `\RuntimeException`, add the static `forPath()` factory with an actionable message.
2. `vendor/bin/testbench make:class VaultFilesystemAdapter` → move to `src/Filesystem/VaultFilesystemAdapter.php`, namespace `Authkit\Authkit\Filesystem`, implement `Illuminate\Contracts\Filesystem\Filesystem` as above.
3. Register `Storage::extend('vault', ...)` in `AuthkitServiceProvider::boot()` — **before** the `runningInConsole()` early return (queue workers and console commands are exactly where a background job would write to a vault disk; registering the driver only for HTTP contexts would silently break console usage).

**Feedback loop:**

- **Inner-loop command:** `vendor/bin/pest --filter=Vault` (seconds).
- **Playground:** a Pest feature test configuring a `vault` disk over a **faked inner disk** (see §7 for the exact pattern — do **not** use `Storage::fake('vault')` directly, it erases the custom driver).
- **Parameterized experiment:** vary file size (small string, a value at the guard boundary, a value one byte over the guard) and content type (`put()` with a string vs. `putFile()` with an `Illuminate\Http\UploadedFile::fake()`).
- **Check:** `vendor/bin/pest --filter=Vault` exits 0.

### Component 4 — Vault Facade (KV)

**Laravel mechanism:** a dedicated `Vault` facade (per the template's named-facade list: `Authkit`, `Vault`, `AuditLog`) backed by a manager class, mirroring how `Storage` backs onto `FilesystemManager`.

**SDK methods wrapped** (all direct passthroughs — WorkOS encrypts KV values **server-side**; there is no client-side envelope construction for this surface, unlike Components 2–3):

| Facade method | SDK method | Notes |
|---|---|---|
| `Vault::set(array $keyContext, string $name, string $value): ObjectMetadata` | `createKv` | Plaintext `$value` sent over the wire; WorkOS encrypts at rest using the KEK resolved from `$keyContext`. |
| `Vault::get(string $name): VaultObject` | `getName` | Fetch + decrypt by unique **name**. |
| `Vault::find(string $id): VaultObject` | `getKv` | Fetch + decrypt by unique **id** (needed because `update`/`delete` are id-keyed). |
| `Vault::update(string $id, string $value, ?string $versionCheck = null): ObjectWithoutValue` | `updateKv` | `$versionCheck` is the optimistic-lock passthrough. |
| `Vault::delete(string $id, ?string $versionCheck = null): void` | `deleteKv` | Same optimistic-lock passthrough. |
| `Vault::metadata(string $id): ObjectWithoutValue` | `listKvMetadata` | Metadata-only read — never touches/returns the value. |
| `Vault::versions(string $id): VersionListResponse` | `listKvVersions` | |
| `Vault::list(...): PaginatedResponse` | `listKv` | Cursor-paginated; forwards `limit`/`before`/`after`/`order`/`search`/`updatedAfter` verbatim. |

**Key design:**

```php
namespace Authkit\Authkit\Vault;

use WorkOS\PaginatedResponse;
use WorkOS\Resource\ObjectMetadata;
use WorkOS\Resource\ObjectWithoutValue;
use WorkOS\Resource\VaultObject;
use WorkOS\Resource\VaultOrder;
use WorkOS\Resource\VersionListResponse;
use WorkOS\WorkOS;

final class VaultManager
{
    public function __construct(private readonly WorkOS $client)
    {
    }

    /** @param array<string, string> $keyContext */
    public function set(array $keyContext, string $name, string $value): ObjectMetadata
    {
        return $this->client->vault()->createKv($keyContext, $name, $value);
    }

    public function get(string $name): VaultObject
    {
        return $this->client->vault()->getName($name);
    }

    public function find(string $id): VaultObject
    {
        return $this->client->vault()->getKv($id);
    }

    public function update(string $id, string $value, ?string $versionCheck = null): ObjectWithoutValue
    {
        return $this->client->vault()->updateKv($id, $value, $versionCheck);
    }

    public function delete(string $id, ?string $versionCheck = null): void
    {
        $this->client->vault()->deleteKv($id, $versionCheck);
    }

    public function metadata(string $id): ObjectWithoutValue
    {
        return $this->client->vault()->listKvMetadata($id);
    }

    public function versions(string $id): VersionListResponse
    {
        return $this->client->vault()->listKvVersions($id);
    }

    public function list(
        ?int $limit = null,
        ?string $before = null,
        ?string $after = null,
        ?VaultOrder $order = null,
        ?string $search = null,
        ?\DateTimeImmutable $updatedAfter = null,
    ): PaginatedResponse {
        return $this->client->vault()->listKv($limit, $before, $after, $order, $search, $updatedAfter);
    }
}
```

```php
namespace Authkit\Authkit\Facades;

use Illuminate\Support\Facades\Facade;

/** @see \Authkit\Authkit\Vault\VaultManager */
final class Vault extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Authkit\Authkit\Vault\VaultManager::class;
    }
}
```

**Implementation steps:**

1. `vendor/bin/testbench make:class VaultManager` → move to `src/Vault/VaultManager.php`, namespace `Authkit\Authkit\Vault`, implement as above.
2. Hand-write `src/Facades/Vault.php` (no generator for facades in Laravel) mirroring the existing `src/Facades/Authkit.php` pattern exactly.
3. Bind `VaultManager::class` singleton in `AuthkitServiceProvider::register()`.
4. Add `"Vault": "Authkit\\Authkit\\Facades\\Vault"` to `composer.json`'s `extra.laravel.aliases`.

**Feedback loop:**

- **Inner-loop command:** `vendor/bin/pest --filter=Vault` (seconds).
- **Playground:** Pest feature test against `Authkit\Authkit\Facades\Vault`, MockHandler-backed.
- **Parameterized experiment:** vary the optimistic-lock outcome (matching `versionCheck` → success; stale `versionCheck` → `ConflictException`) and the read shape (`get` by name vs. `find` by id vs. `metadata`-only).
- **Check:** `vendor/bin/pest --filter=Vault` exits 0.

### Component 5 — BYOK & `key_context` Conventions (documentation only)

**Why this is trivial and skips a feedback loop:** BYOK/CMK enablement is a WorkOS-account-level toggle plus an Admin Portal flow — both owned by **Phase 6** (`GenerateLinkIntent::BringYourOwnKey`, one of the portal's 7 intents). This phase contributes **zero code** for BYOK — only the convention that makes Vault's `key_context` correctly route to a BYOK-enabled org's own CMK once Phase 6 exists:

- BYOK requires WorkOS to have enabled it for the account (dashboard/sales-enablement step, not something this package can toggle).
- Once enabled, an org's Vault operations route to their own CMK when — and only when — the `key_context` passed to `encrypt()`/`createKv()` includes the org's `organization_id` in the form WorkOS expects for CMK matching.
- Component 1's `DefaultVaultKeyContextResolver` already includes `organization_id` when the model exposes `workosOrganizationId()` — this is the mechanism that makes BYOK "just work" for vaulted attributes once an org is BYOK-enabled, with no Vault-phase code changes needed.
- For the KV facade, the caller supplies `$keyContext` directly to `Vault::set()` — the same convention (include `organization_id`) applies, but enforcing it is the caller's responsibility since `set()` is a raw passthrough (see Component 4).
- **Pointer, not implementation**: minting the `bring_your_own_key` portal link is Phase 6's `AdminPortal::generateLink()` wrapper. This phase documents the dependency; it does not build it.

No config keys, enums, or DTOs are introduced by this component — it is pure documentation (the docblocks already present in Components 1 and 4, plus this section), so it has no feedback loop to define.

## 5. File Changes

### New files

| Path | Purpose | Scope item traced |
|---|---|---|
| `src/Vault/ResolvesVaultKeyContext.php` | Key-context resolver interface | Vaulted cast — "key_context derivation configurable" |
| `src/Vault/DefaultVaultKeyContextResolver.php` | Default resolver: model+attribute base, org-aware, model-override hook | same |
| `src/Vault/VaultCrypto.php` | `encrypt()`/`decrypt()` wrapper shared by the cast and the filesystem driver | Vaulted cast (attribute envelope encryption) + vault filesystem driver (envelope, per-file data key) |
| `src/Vault/VaultManager.php` | KV CRUD/list/versions/metadata passthrough | Vault facade for KV |
| `src/Casts/Vaulted.php` | `CastsAttributes` implementation | Vaulted Eloquent cast |
| `src/Filesystem/VaultFilesystemAdapter.php` | `Illuminate\Contracts\Filesystem\Filesystem` decorator | vault filesystem driver wrapping any disk |
| `src/Facades/Vault.php` | Facade → `VaultManager` | Vault facade for KV |
| `src/Exceptions/VaultFileTooLargeException.php` | Size-guard exception | vault filesystem driver — "BYOK data-key encryption" size-guard requirement |
| `src/Exceptions/InvalidVaultKeyContextResolverException.php` | Fail-fast exception naming the bad `authkit.vault.key_context_resolver` config value | Shared Failure-Mode Prompts: "Config missing/empty (fail fast with actionable exception naming the config key...)" |
| `tests/Unit/Vault/VaultKeyContextResolverTest.php` | Resolver unit coverage (no I/O) | Testing requirement for Component 1 |
| `tests/Feature/VaultedCastTest.php` | Cast round-trip + corruption + outage | Success criterion: "Vaulted cast round-trips a model attribute" |
| `tests/Feature/VaultFilesystemDriverTest.php` | Disk round-trip + size guard + passthroughs | Success criterion: "the vault filesystem driver round-trips a file on a wrapped disk" |
| `tests/Feature/VaultFacadeTest.php` | KV CRUD + version_check conflict | Success criterion: "the KV facade CRUDs a secret" |
| `workbench/database/migrations/{timestamp}_create_vault_demo_records_table.php` | Fixture table with a `text()` vaulted column | Minimal fixture required to test a real `CastsAttributes` cast against an Eloquent model — no cast can be tested without one |
| `workbench/app/Models/VaultDemoRecord.php` | Fixture model applying the `Vaulted` cast | same |

### Modified files

| Path | Change |
|---|---|
| `src/AuthkitServiceProvider.php` | Add `ResolvesVaultKeyContext` binding, `VaultCrypto`/`VaultManager` singletons (register); `Storage::extend('vault', ...)` registration (boot, before the console early-return) |
| `config/authkit.php` | Add a `vault` key (`key_context_resolver`, `filesystem.max_encrypt_bytes`) — file itself owned/created by Phase 1; this phase only adds its own subtree |
| `composer.json` | Add `"Vault": "Authkit\\Authkit\\Facades\\Vault"` to `extra.laravel.aliases` |

**Explicitly not touched:** `database/migrations/` (package migrations) — Vault introduces no package-level schema; `routes/` — Vault has no HTTP surface; `resources/views/`, `lang/` — no user-facing UI/strings.

## 6. Service Provider Registration Diff

```php
// register() — appended after the existing mergeConfigFrom/singleton(Authkit::class) lines
$this->app->bind(
    \Authkit\Authkit\Vault\ResolvesVaultKeyContext::class,
    function ($app) {
        $resolverClass = config(
            'authkit.vault.key_context_resolver',
            \Authkit\Authkit\Vault\DefaultVaultKeyContextResolver::class,
        );

        try {
            return $app->make($resolverClass);
        } catch (\Illuminate\Contracts\Container\BindingResolutionException $e) {
            throw \Authkit\Authkit\Exceptions\InvalidVaultKeyContextResolverException::forConfiguredClass(
                $resolverClass,
                $e,
            );
        }
    },
);

$this->app->singleton(\Authkit\Authkit\Vault\VaultCrypto::class);
$this->app->singleton(\Authkit\Authkit\Vault\VaultManager::class);
```

The `try`/`catch` above is the fix for the "Config missing (`key_context_resolver` misconfigured)" failure mode in §8: a bad class name now fails fast with an exception that names the offending config value, at bind time, rather than surfacing as a raw `BindingResolutionException` with no mention of `authkit.vault.key_context_resolver` deep inside the first `set()` call.

```php
namespace Authkit\Authkit\Exceptions;

final class InvalidVaultKeyContextResolverException extends \RuntimeException
{
    public static function forConfiguredClass(string $configuredClass, \Throwable $previous): self
    {
        return new self(
            "The class configured at 'authkit.vault.key_context_resolver' ({$configuredClass}) could not be ".
            'resolved from the container. Confirm the class exists, is autoloadable, and implements '.
            \Authkit\Authkit\Vault\ResolvesVaultKeyContext::class.'.',
            previous: $previous,
        );
    }
}
```

```php
// boot() — inserted BEFORE the `if (! $this->app->runningInConsole()) { return; }` guard,
// because console/queue contexts are exactly where a job might write to a vault disk
\Illuminate\Support\Facades\Storage::extend('vault', function ($app, array $config) {
    $diskName = $config['disk']
        ?? throw new \InvalidArgumentException(
            "The 'vault' filesystem driver requires a 'disk' key naming the underlying disk to wrap."
        );

    return new \Authkit\Authkit\Filesystem\VaultFilesystemAdapter(
        inner: \Illuminate\Support\Facades\Storage::disk($diskName),
        crypto: $app->make(\Authkit\Authkit\Vault\VaultCrypto::class),
        context: $config['context'] ?? ['disk' => $diskName],
        maxEncryptBytes: $config['max_encrypt_bytes']
            ?? config('authkit.vault.filesystem.max_encrypt_bytes', 10 * 1024 * 1024),
    );
});
```

Both `VaultCrypto` and `VaultManager` constructor-inject `\WorkOS\WorkOS` — Laravel's container autowires it from **Phase 1's** binding. See Open Items for the explicit dependency this creates.

## 7. Testing Requirements

**Test path:** 100% MockHandler. Emulate has zero Vault coverage (confirmed in the context brief's emulate inventory: "ZERO: Vault, Agents"), so no test in this phase boots `workos/emulate`.

**Naming convention so `--filter=Vault` catches everything:** Pest's `--filter` matches generated test names, which are derived from `it()`/`describe()` descriptions, not file paths. Every test file in this phase wraps its cases in a top-level `describe('Vault', function () { ... });` block (nesting a sub-`describe` per component is fine) so that the contract's exact check command, `vendor/bin/pest --filter=Vault`, catches all four files.

**The canonical MockHandler round-trip pattern** (reused by the cast and filesystem tests — this is the one non-obvious piece of test infrastructure this phase needs, since a naive mock would only prove "the right endpoint was called," not that plaintext actually survives real AES-GCM):

```php
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use WorkOS\WorkOS;

function fakeVaultRoundTrip(): WorkOS
{
    $rawKey = random_bytes(32);       // AES-256 key, shared across both mocked calls
    $b64Key = base64_encode($rawKey);
    $b64WrappedBlob = base64_encode('opaque-wrapped-key-blob'); // never unwrapped locally, content is irrelevant

    $handler = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode([
            'context' => ['probe' => 'value'],
            'data_key' => $b64Key,
            'encrypted_keys' => $b64WrappedBlob,
            'id' => 'key_123',
        ])), // consumed by createDataKey() inside encrypt()
        new Response(200, [], json_encode([
            'data_key' => $b64Key,      // SAME key — this is what makes the round trip real
            'id' => 'key_123',
        ])), // consumed by createDecrypt() inside decrypt()
    ]));

    return new WorkOS(apiKey: 'sk_test_123', clientId: 'client_123', handler: $handler);
}
```

Because `decrypt()` re-extracts whatever raw key blob `encrypt()` embedded and sends it back to `createDecrypt`, and our mock returns the **same** `data_key` both times, the local AES-GCM decrypt genuinely recovers the original plaintext — this test would fail if the envelope format, the LEB128 length-prefix framing, or the IV/tag placement in `Vault.php` were broken, not just if the wrong endpoint were hit.

**Filesystem driver test — do not use `Storage::fake('vault')`:** `Storage::fake($disk)` replaces whatever driver `$disk` names with a plain local fake — calling it on `'vault'` directly **erases the custom driver entirely**, and the test would pass even with completely broken encryption. Instead, fake the *inner* disk and point a real `vault` disk at it:

```php
config()->set('filesystems.disks.vault-test-inner', [
    'driver' => 'local',
    'root' => storage_path('framework/testing/vault'),
]);
config()->set('filesystems.disks.vault', [
    'driver' => 'vault',
    'disk' => 'vault-test-inner',
]);

Storage::fake('vault-test-inner'); // safe — this is a plain local disk, not the decorator

app()->instance(\WorkOS\WorkOS::class, fakeVaultRoundTrip());

Storage::disk('vault')->put('secret.txt', 'plaintext-content');

expect(Storage::disk('vault-test-inner')->get('secret.txt'))->not->toBe('plaintext-content'); // proves it's encrypted at rest
expect(Storage::disk('vault')->get('secret.txt'))->toBe('plaintext-content'); // proves the round trip
```

**Key cases per file:**

- `tests/Unit/Vault/VaultKeyContextResolverTest.php` — the four-case dataset from Component 1's feedback loop (no MockHandler needed — pure resolver logic).
- `tests/Feature/VaultedCastTest.php`:
  - Round-trips a plaintext string through `VaultDemoRecord::create(['secret' => '...'])` then a fresh `->find()`, using the pattern above.
  - Setting `secret = null` stores `null`, no crypto call made (assert the MockHandler queue is untouched).
  - **Envelope corruption**: take a valid envelope, corrupt a byte near the end (breaks the GCM tag), assert `decrypt()` (via re-fetching the model) throws `\RuntimeException`.
  - **Data-key outage**: construct a *separate* `WorkOS` client with `maxRetries: 0` and a single mocked 500 response, `app()->instance(WorkOS::class, ...)`, assert `VaultDemoRecord::create(['secret' => 'x'])` throws a `\WorkOS\Exception\WorkOSException` and that **no row was inserted** (`VaultDemoRecord::count()` is 0) — proves fail-closed.
  - **Misconfigured key-context resolver**: `config(['authkit.vault.key_context_resolver' => 'App\\NonexistentClass'])`, assert `VaultDemoRecord::create(['secret' => 'x'])` throws `\Authkit\Authkit\Exceptions\InvalidVaultKeyContextResolverException` whose message contains both the bad class name and the string `authkit.vault.key_context_resolver`; assert **no row was inserted** and the MockHandler queue is untouched (the container throws before any network call is attempted) — same fail-closed shape as the data-key outage case, but for a config error instead of a WorkOS-down error.
- `tests/Feature/VaultFilesystemDriverTest.php`:
  - Round-trip via the fake-inner-disk pattern above.
  - `putFile()` with `\Illuminate\Http\UploadedFile::fake()->create(...)`.
  - **Size guard**: set `max_encrypt_bytes` to a small value, `put()` a larger string, assert `VaultFileTooLargeException`, and assert the MockHandler queue still has its original count of unconsumed responses (proves the guard runs *before* any network call).
  - `exists()`/`delete()`/`copy()`/`move()` passthrough to the inner disk.
- `tests/Feature/VaultFacadeTest.php`:
  - `Vault::set()` → `Vault::get()` round trip (mocked `createKv`/`getName` responses — plaintext passthrough, no local AES-GCM involved here).
  - `Vault::update()` with a stale `versionCheck` → mock a 409 response, assert `\WorkOS\Exception\ConflictException`.
  - `Vault::metadata()` returns `ObjectWithoutValue` (no `value` property exists on that DTO — compile-time proof metadata never carries the secret).
  - `Vault::list()` returns a `PaginatedResponse`.

**Seed data:** none — every test constructs its own MockHandler response queue inline; there is no shared fixture file (each response shape is small enough to inline, and inlining keeps the request/response pairing for the round-trip pattern legible).

## 8. Failure Modes

| Failure | Trigger | Behavior (by design) | Why it matters |
|---|---|---|---|
| **Data-key creation outage** | `createDataKey` fails after the SDK's own 429/5xx retries exhaust (`ConnectionException`/`ServerException`/`RateLimitExceededException`) | `encrypt()` throws before any ciphertext exists; the cast's `set()` throws before `Model::save()` builds its INSERT/UPDATE; the filesystem driver's `put()` throws before `$inner->put()` is ever called | **Fail closed, by construction, not by a try/catch.** Do not add a catch clause anywhere in this stack that falls back to storing plaintext "to keep the app working" — there is no code path in this design that writes unencrypted data, and it must stay that way. |
| **Envelope corruption / tamper** | Ciphertext or tag bytes altered (truncated `VARCHAR` column, manual DB edit, bit rot) | `openssl_decrypt` fails GCM tag verification → `Vault.php`'s `aesGcmDecrypt` throws `\RuntimeException('AES-GCM decryption failed')`; malformed base64/LEB128 framing throws `\InvalidArgumentException` instead | This is the **integrity check working correctly**, not a bug to route around. The single most common real-world trigger is a vaulted column migrated as `string()` (VARCHAR 255) instead of `text()` — the fix is schema discipline, not a code-level retry. |
| **Key-context drift = silent cross-tenant key-sharing (not a decrypt failure)** | Two orgs' models resolve to the *same* `key_context` (e.g., `workosOrganizationId()` is missing/buggy and always returns `null`) | Decryption still **succeeds** — the wrapped data-key blob embedded in the envelope is self-describing and `createDecrypt` does not require the original context to be re-supplied. The isolation loss is invisible to any functional round-trip test. | `key_context` governs **which KEK wraps a fresh data key at encrypt time**, not "which secret this is." A context bug does not fail loudly; it silently routes two tenants' data through the same key-encryption-key — a BYOK/compliance violation that only a context-derivation unit test (Component 1) can catch, because a round-trip test alone will pass either way. |
| **Vaulted column used as a lookup key** | `WHERE secret = ?`, a unique index, or a `LIKE` query against a vaulted attribute | Every `encrypt()` call mints a fresh data key and a fresh random IV — ciphertext is non-deterministic even for identical plaintext across saves. Queries silently return zero rows; unique constraints never reject a "duplicate." | Not a code defect — a documented, structural limitation of envelope encryption. Callers needing lookup semantics must maintain a separate deterministic column outside this cast. |
| **Oversized file vs. the size guard** | `put()`/`putFile()`/`writeStream()` content exceeds `max_encrypt_bytes` | `VaultFileTooLargeException` thrown **before** `createDataKey` is called — the guard is a local `strlen()` check, not a WorkOS-side rejection | Prevents both an OOM risk (no streaming cipher exists in the SDK) and a wasted network round trip on a file that was going to be rejected anyway. Raising the limit is a config change, not a code change. |
| **Rekey/rotation** | An org's KEK needs to be rotated (compromise, compliance cadence) | The SDK exposes `Vault::createRekey(array $context, string $encryptedKeys)` (decrypt-then-rewrap under a new context), but **no automated rotation command ships in this phase** — it is not one of the three approved scope shapes (cast, file driver, KV facade) | Deliberately out of scope — see §3. An app needing rekey today resolves `\WorkOS\WorkOS::class` from the container directly and calls `createRekey` itself; this is the one place this phase's "no direct SDK in consumer code" doctrine is knowingly not enforced, because rotation tooling was never promised by any success criterion. |
| **`append()`/`prepend()` cost** | Called on a vault-encrypted disk | Implemented as decrypt-then-concatenate-then-re-encrypt (full `get()` + `put()`), because there is no in-place ciphertext append | Every call costs a full file round trip **plus** a fresh `createDataKey` network call. Do not use this driver for log-like append-heavy files. |
| **`size()` reports the wrong number** | Anything calls `Storage::disk('vault')->size($path)` | Returns the **encrypted envelope's** size on the underlying disk (base64-inflated ciphertext + IV/tag/key-blob header), not the original plaintext size | A deliberate, documented tradeoff (per the phase brief: "metadata ... passthrough") — recomputing plaintext size would mean decrypting on every `size()` call, defeating the point of cheap metadata reads. |
| **`versionCheck` race** | Two writers call `Vault::update()`/`Vault::delete()` concurrently without supplying `versionCheck` | No error — last write silently wins; WorkOS returns `ConflictException` **only** when a caller supplies a `versionCheck` that no longer matches | Optimistic locking here is **opt-in, not default**. A consumer who skips `versionCheck` gets zero race protection — document this at the facade call site, don't assume it's automatic. |
| **`createKv` duplicate-name behavior — UNVERIFIED** | Calling `Vault::set()` twice with the same `name` | Not confirmed against live docs whether this overwrites, conflicts, or is otherwise defined | Flagged per the context brief's instruction to name unverified facts rather than assert them. Until confirmed: treat retries of a `set()` call after a timeout as unsafe: prefer `get()`-then-`update()`-with-`versionCheck` for anything idempotency-sensitive. |
| **MockHandler/production wire-format drift** | The vendored Resource DTOs (`ObjectMetadata`, `VaultObject`, etc.) change shape in a future SDK bump, but every Vault test is a hand-built fixture | Tests keep passing against a stale fixture shape even if the real API has moved | The **inverse** of the usual "emulate drift" risk this template warns about: because Vault has *no* emulate cross-check at all, this phase's tests can drift from reality with nothing to catch it. Mitigation is procedural, not automatable in this phase: re-validate fixture shapes against `vendor/workos/workos-php/lib/Resource/*.php` on every SDK version bump. |
| **Config missing (`key_context_resolver` misconfigured)** | `config('authkit.vault.key_context_resolver')` names a class that isn't bound/doesn't exist | The `register()` binding (§6) catches the container's `BindingResolutionException` and rethrows `\Authkit\Authkit\Exceptions\InvalidVaultKeyContextResolverException`, naming both the bad class and the `authkit.vault.key_context_resolver` config key, at first resolution (e.g. the cast's `set()` or the filesystem driver's `put()`) | Fails fast with an actionable exception naming the config key, per the template's Shared Failure-Mode Prompts — no more raw container exception with no mention of which config key was wrong. Covered by a dedicated test case in `tests/Feature/VaultedCastTest.php` (§7). |

## 9. Deviations from the Template

None beyond what's already called out inline above (the MockHandler-only test path is the template's own stated behavior for zero-emulate-coverage areas, not a deviation from it). Feature test files for this phase live flat at `tests/Feature/{Name}Test.php` (`VaultedCastTest.php`, `VaultFilesystemDriverTest.php`, `VaultFacadeTest.php`), matching the template's `tests/Feature/{Area}Test.php` convention exactly — no `Vault/` subdirectory. An earlier draft of this spec nested these three files under `tests/Feature/Vault/`; that nesting has been removed because it would have silently evaded the contract's success-criteria check, `ls tests/Feature/*Test.php | wc -l` (a non-recursive glob), and because every other phase spec in this set (2, 5, 6, 7, 8, 10, 11, 12) uses the flat layout.

## 10. Validation Commands

```bash
composer analyse                        # PHPStan (larastan)
composer lint:check                     # Pint check-only
composer test:types                     # Pest type coverage --min=100
vendor/bin/pest --filter=Vault          # this phase's full suite (seconds)
composer test                           # full chain — must be green before commit
```

## Open Items

1. **Exact WorkOS client container binding from Phase 1 is assumed, not confirmed.** This spec assumes Phase 1 binds `\WorkOS\WorkOS::class` as a singleton directly resolvable via constructor autowiring (config-driven construction, injectable Guzzle handler for tests). If Phase 1 instead exposes it only through an intermediary method (e.g. `Authkit::client()`), every constructor-injected `\WorkOS\WorkOS $client` in `VaultCrypto`/`VaultManager` needs updating to resolve through that accessor instead. Confirm against the landed Phase 1 spec before implementing.
2. **Shared MockHandler test helper may already exist from Phase 1.** The context brief mentions "MockHandler helpers" as Phase 1 test-harness plumbing. This spec's `fakeVaultRoundTrip()` is written as a self-sufficient fallback; if Phase 1 ships a shared helper (e.g. a `UsesMockHandler` Pest trait), prefer it and adapt the pattern shown here to it rather than maintaining a parallel one.
3. **`createKv` duplicate-`name` behavior is unverified** against live WorkOS docs (see Failure Modes). Confirm before documenting any idempotency guarantee for `Vault::set()`.
4. **Org-awareness duck-typing (`workosOrganizationId()`) is this phase's own invention**, not imported from Phase 3's `HasWorkosOrganization` trait, because Vault's only prereq is Phase 1. Once Phase 3 lands, confirm that trait exposes a method with this exact name/signature (or add it) so the default resolver's org-awareness activates automatically rather than requiring every model to hand-roll the method.
5. **AAD (`associatedData`) binding was deliberately left out of the usable-core design.** Binding ciphertext to e.g. the model's primary key would add ciphertext-swap protection between rows, but a model's key is not reliably available inside `set()` before the first save (new/unsaved models). Candidate for a Full-tier enhancement if a future phase revisits Vault — not required by any current success criterion.
6. **Resolved**: `key_context_resolver` misconfiguration now has a friendly failure path. The `register()` binding (§6) wraps the container's `$app->make()` call in a try/catch and rethrows `\Authkit\Authkit\Exceptions\InvalidVaultKeyContextResolverException`, naming both the bad class and the `authkit.vault.key_context_resolver` config key (see Failure Modes, "Config missing" row, and the dedicated test case in `tests/Feature/VaultedCastTest.php`, §7). This was originally deferred as a candidate "small guard clause" for later; it is a few lines and is now part of the phase's usable-core design rather than an open item.
