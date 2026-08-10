<?php

declare(strict_types=1);

use Authkit\Authkit\Auth\WorkosApiKeyActor;
use Authkit\Authkit\AuthkitServiceProvider;
use Authkit\Authkit\Casts\Vaulted;
use Authkit\Authkit\Filesystem\VaultFilesystemAdapter;
use Authkit\Authkit\Http\Requests\AuthKitAuthenticationRequest;
use Authkit\Authkit\Http\Requests\AuthKitLoginRequest;
use Authkit\Authkit\Http\Requests\AuthKitLogoutRequest;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;
use Workbench\App\Models\Organization;

/**
 * Makes the "Laravel-native maximalism" doctrine mechanically falsifiable:
 * every mechanism the contract names must exist AND be registered, not merely
 * exist as an unwired class. Registration is proven by resolving through
 * Laravel's own managers (Auth::guard, Feature::store, Storage::disk) with
 * throwaway probe configs — the contract Laravel expects, not reflection into
 * how the registration happened.
 *
 * Each mechanism gets its own test so a failing run names WHICH idiom
 * regressed. No combined booleans, no early returns.
 *
 * On the contract's "Gate/Blade directives" idiom: no phase shipped (or
 * promised) a custom Blade directive — the landed mechanism is the pair of
 * Gate::before hooks (JWT-claims RBAC and API-key permissions), which
 * Laravel's built-in @can directive rides for free. The Gate wiring is
 * asserted behaviorally below rather than by a fabricated directive name — a
 * fake-passing tautology is worse than an honest reconciliation for a suite
 * whose purpose is falsifiability.
 */
test('IdiomCoverage: workos guard driver is registered', function (): void {
    config()->set('auth.guards.workos-idiom-probe', ['driver' => 'workos', 'provider' => 'workos']);

    expect(fn () => Auth::guard('workos-idiom-probe'))->not->toThrow(InvalidArgumentException::class);
});

test('IdiomCoverage: authkit-key guard driver is registered, with its default guard entry', function (): void {
    // The provider seeds auth.guards.authkit-key itself — resolving the
    // bare name proves both the driver extension AND the default entry.
    expect(fn () => Auth::guard('authkit-key'))->not->toThrow(InvalidArgumentException::class);
});

test('IdiomCoverage: workos guard entry is seeded by the provider when the app defines none', function (): void {
    // authkit:install deliberately never edits config/auth.php, so the
    // provider must seed the entry itself or a fresh install 500s with
    // "Auth guard [workos] is not defined." on the first auth:workos route.
    config()->offsetUnset('auth.guards.workos');

    (new AuthkitServiceProvider(app()))->register();

    expect(config('auth.guards.workos'))->toBe(['driver' => 'workos', 'provider' => 'users']);
});

test('IdiomCoverage: a consumer-defined workos guard entry wins over the seeded default', function (): void {
    config()->set('auth.guards.workos', ['driver' => 'workos', 'provider' => 'admins']);

    (new AuthkitServiceProvider(app()))->register();

    expect(config('auth.guards.workos.provider'))->toBe('admins');
});

test('IdiomCoverage: middleware aliases are registered', function (): void {
    $aliases = app(Router::class)->getMiddleware();

    expect($aliases)->toHaveKeys(['authkit.session', 'authkit.org', 'authkit.mcp', 'authkit.webhook']);
});

test('IdiomCoverage: auth form requests exist independently of the routes', function (): void {
    foreach ([
        AuthKitLoginRequest::class,
        AuthKitAuthenticationRequest::class,
        AuthKitLogoutRequest::class,
    ] as $class) {
        expect(class_exists($class))->toBeTrue("$class does not exist.");
        expect(is_subclass_of($class, FormRequest::class))->toBeTrue("$class is not a FormRequest.");
    }
});

test('IdiomCoverage: Vaulted cast exists and implements CastsAttributes', function (): void {
    expect(class_exists(Vaulted::class))->toBeTrue();
    expect(in_array(CastsAttributes::class, (array) class_implements(Vaulted::class), true))->toBeTrue();
});

test('IdiomCoverage: generator command is registered', function (): void {
    expect(Artisan::all())->toHaveKey('make:workos-listener');
});

test('IdiomCoverage: Pennant driver is registered as the workos store', function (): void {
    // The provider injects pennant.stores.workos itself; resolving the store
    // proves the driver extension and the store entry together.
    expect(fn () => Feature::store('workos'))->not->toThrow(InvalidArgumentException::class);
});

test('IdiomCoverage: vault filesystem driver is registered', function (): void {
    config()->set('filesystems.disks.vault-idiom-probe', ['driver' => 'vault', 'disk' => 'local']);

    expect(Storage::disk('vault-idiom-probe'))->toBeInstanceOf(VaultFilesystemAdapter::class);
});

test('IdiomCoverage: workosWebhooks route macro is registered', function (): void {
    expect(Route::hasMacro('workosWebhooks'))->toBeTrue();
});

test('IdiomCoverage: request organization() macro is registered', function (): void {
    expect(Request::hasMacro('organization'))->toBeTrue();
});

test('IdiomCoverage: Gate::before hooks are wired (API-key permissions reach Gate)', function (): void {
    // Behavioral probe: no ability named idiom.probe is defined anywhere, so
    // an allow can ONLY come from the package's registered Gate::before hook
    // reading the actor's key permissions. The claims-side hook is proven
    // end-to-end by the Acceptance suite's can()-from-claims assertions.
    $actor = new WorkosApiKeyActor(
        organization: new Organization,
        permissions: ['idiom.probe'],
        apiKeyId: 'key_idiom_probe',
    );

    expect(Gate::forUser($actor)->allows('idiom.probe'))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('idiom.other'))->toBeFalse();
});
