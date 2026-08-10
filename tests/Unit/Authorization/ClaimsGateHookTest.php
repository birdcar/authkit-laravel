<?php

declare(strict_types=1);

use Authkit\Authkit\Authorization\ClaimsGateHook;
use Authkit\Authkit\Contracts\HasAccessTokenClaims;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\Auth;

uses(UsesWorkosMockHandler::class);

// Test path: zero-HTTP unit. The hook is invoked directly against an in-memory
// stub guard, and the Guzzle stack is an EMPTY MockHandler queue — if the hook
// (or anything it calls) ever attempts an HTTP request, Guzzle throws
// "Mock queue is empty" and the test fails loudly. That structurally proves
// the zero-HTTP claim rather than merely implying it.

final class ClaimsGateHookStubGuard implements Guard, HasAccessTokenClaims
{
    /**
     * @param  array<string, mixed>|null  $claims
     */
    public function __construct(private readonly ?array $claims) {}

    public function accessTokenClaims(): ?array
    {
        return $this->claims;
    }

    public function check(): bool
    {
        return $this->claims !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function user(): ?Authenticatable
    {
        return null;
    }

    public function id(): int|string|null
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function hasUser(): bool
    {
        return false;
    }

    public function setUser(Authenticatable $user): static
    {
        return $this;
    }
}

final class ClaimsGateHookClaimlessStubGuard implements Guard
{
    public function check(): bool
    {
        return false;
    }

    public function guest(): bool
    {
        return true;
    }

    public function user(): ?Authenticatable
    {
        return null;
    }

    public function id(): int|string|null
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function hasUser(): bool
    {
        return false;
    }

    public function setUser(Authenticatable $user): static
    {
        return $this;
    }
}

function swapWorkosGuardForClaimsHookTest(Guard $guard): void
{
    Auth::extend('workos', fn (): Guard => $guard);
    Auth::forgetGuards();
}

beforeEach(function (): void {
    $this->fakeWorkosResponses([]);

    $this->hook = new ClaimsGateHook;
});

it('returns true when the ability matches a permissions[] claim entry', function (): void {
    swapWorkosGuardForClaimsHookTest(new ClaimsGateHookStubGuard(['permissions' => ['posts.edit', 'posts.view']]));

    expect(($this->hook)(null, 'posts.edit'))->toBeTrue();
});

it('returns true when the ability matches a roles[] claim entry', function (): void {
    swapWorkosGuardForClaimsHookTest(new ClaimsGateHookStubGuard(['roles' => ['admin']]));

    expect(($this->hook)(null, 'admin'))->toBeTrue();
});

it('returns true when the ability matches the singular role claim fallback', function (): void {
    swapWorkosGuardForClaimsHookTest(new ClaimsGateHookStubGuard(['role' => 'member']));

    expect(($this->hook)(null, 'member'))->toBeTrue();
});

it('checks the singular role claim even when a plural roles[] claim is present', function (): void {
    swapWorkosGuardForClaimsHookTest(new ClaimsGateHookStubGuard(['roles' => ['admin'], 'role' => 'member']));

    expect(($this->hook)(null, 'member'))->toBeTrue();
});

it('returns null, not false, when claims exist but nothing matches', function (): void {
    swapWorkosGuardForClaimsHookTest(new ClaimsGateHookStubGuard(['permissions' => ['posts.view'], 'roles' => ['member']]));

    $result = ($this->hook)(null, 'posts.delete');

    expect($result)->toBeNull()
        ->and($result)->not->toBeFalse();
});

it('returns null when the guard does not implement HasAccessTokenClaims', function (): void {
    swapWorkosGuardForClaimsHookTest(new ClaimsGateHookClaimlessStubGuard);

    expect(($this->hook)(null, 'posts.edit'))->toBeNull();
});

it('returns null when there is no authenticated session', function (): void {
    swapWorkosGuardForClaimsHookTest(new ClaimsGateHookStubGuard(null));

    expect(($this->hook)(null, 'posts.edit'))->toBeNull();
});

it('never returns false under any input', function (?array $claims, string $ability): void {
    swapWorkosGuardForClaimsHookTest(new ClaimsGateHookStubGuard($claims));

    expect(($this->hook)(null, $ability))->not->toBeFalse();
})->with([
    'no session' => [null, 'posts.edit'],
    'empty claims' => [[], 'posts.edit'],
    'matching permission' => [['permissions' => ['posts.edit']], 'posts.edit'],
    'non-matching permission' => [['permissions' => ['posts.view']], 'posts.edit'],
    'garbage permissions claim (string, not list)' => [['permissions' => 'posts.edit'], 'posts.edit'],
    'garbage roles claim (int)' => [['roles' => 42], 'admin'],
    'non-string entries mixed into the list' => [['permissions' => [null, 42, 'posts.edit']], 'posts.edit'],
    'garbage singular role (array)' => [['role' => ['admin']], 'admin'],
    'nothing matches anywhere' => [['permissions' => ['a'], 'roles' => ['b'], 'role' => 'c'], 'posts.edit'],
]);

it('makes zero HTTP calls while evaluating claims', function (): void {
    swapWorkosGuardForClaimsHookTest(new ClaimsGateHookStubGuard(['permissions' => ['posts.edit']]));

    ($this->hook)(null, 'posts.edit');
    ($this->hook)(null, 'posts.delete');

    expect($this->workosRequestHistory)->toHaveCount(0);
});
