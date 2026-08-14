<?php

declare(strict_types=1);

use Authkit\Authkit\AuditLogManager;
use Authkit\Authkit\Authorization\FgaChecker;
use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Facades\AuditLog;
use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Groups\GroupManager;
use Authkit\Authkit\Invitations\InvitationManager;
use Authkit\Authkit\Pipes\PipesManager;
use Authkit\Authkit\Testing\AuthkitFake;
use Authkit\Authkit\Testing\Fakes\ApiKeysFake;
use Authkit\Authkit\Testing\Fakes\FgaFake;
use Authkit\Authkit\Testing\Fakes\GroupsFake;
use Authkit\Authkit\Testing\Fakes\InvitationsFake;
use Authkit\Authkit\Testing\Fakes\PipesFake;
use Authkit\Authkit\Testing\Fakes\Support\FakeVaultCrypto;
use Authkit\Authkit\Testing\Fakes\VaultFake;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use Authkit\Authkit\Vault\VaultCrypto;
use Authkit\Authkit\Vault\VaultManager;
use Illuminate\Support\Facades\Storage;

uses(UsesWorkosMockHandler::class);

beforeEach(function (): void {
    $this->migratePackageDatabase();
});

it('fakes every manager by default and wires the container to the fakes', function (): void {
    $fake = Authkit::fake();

    expect($fake)->toBeInstanceOf(AuthkitFake::class)
        ->and(app(FgaChecker::class))->toBe($fake->fga())
        ->and(app(InvitationManager::class))->toBe($fake->invitations())
        ->and(app(AuditLogManager::class))->toBe($fake->auditLog())
        ->and(app(WorkosClientManager::class))->toBe($fake->apiKeys())
        ->and(app(VaultManager::class))->toBe($fake->vault())
        ->and(app(VaultCrypto::class))->toBe($fake->vault()->crypto())
        ->and(app(PipesManager::class))->toBe($fake->pipes())
        ->and(app(GroupManager::class))->toBe($fake->groups())
        // The facade root stays the REAL Authkit — only bindings changed.
        ->and(Authkit::fga())->toBe($fake->fga());
});

it('supports the fluent scripting chain from the spec', function (): void {
    Authkit::fake()->fga()->allow('projects.view', '42', 'project')->deny('projects.delete', '42', 'project');

    expect(Authkit::check('projects.view', '42', 'project', 'om_test'))->toBeTrue()
        ->and(Authkit::check('projects.delete', '42', 'project', 'om_test'))->toBeFalse();
});

it('keeps unfaked managers real under a partial fake', function (): void {
    $fake = Authkit::fake(['fga']);

    expect($fake->fga())->toBeInstanceOf(FgaFake::class)
        // Bindings untouched: the container still serves the real classes.
        ->and(app(VaultManager::class))->not->toBeInstanceOf(VaultFake::class)
        ->and(app(InvitationManager::class))->not->toBeInstanceOf(InvitationsFake::class)
        ->and(app(WorkosClientManager::class))->not->toBeInstanceOf(ApiKeysFake::class)
        // And the facade keeps serving them for application code.
        ->and(Authkit::invitations())->not->toBeInstanceOf(InvitationsFake::class);
});

it('throws a clear LogicException when scripting a manager that was not faked', function (): void {
    $fake = Authkit::fake(['vault']);

    expect(fn (): FgaFake => $fake->fga())
        ->toThrow(LogicException::class, "Authkit::fake(['fga'])");
});

it('rejects unknown manager names with the valid list', function (): void {
    expect(fn (): AuthkitFake => Authkit::fake(['fgaa']))
        ->toThrow(InvalidArgumentException::class, 'fga, invitations, memberships, audit-log');
});

it('forwards facade assertion calls to the bound fake', function (): void {
    Authkit::fake(['audit-log']);

    AuditLog::log('task.created', [['id' => 'task_1', 'type' => 'task']], organizationId: 'org_direct');

    // The AuditLog facade resolves the container binding — now the fake — so
    // assertions read exactly like the spec's example.
    AuditLog::assertLogged('task.created');
});

it('guides an unfaked facade assertion to Authkit::fake instead of fataling', function (): void {
    // No fake bound: the facade forwards assertLogged to the REAL manager,
    // which must answer with the spec-mandated guidance, not an undefined-
    // method Error.
    expect(fn () => AuditLog::assertLogged('task.created'))
        ->toThrow(LogicException::class, "Authkit::fake(['audit-log'])");
});

it('keeps the api-keys fake bound when the MockHandler harness resets the client', function (): void {
    $fake = Authkit::fake(['api-keys']);

    expect(app(WorkosClientManager::class))->toBe($fake->apiKeys());

    // UsesWorkosMockHandler forgets the client-manager instance on every
    // call — an instance() binding would silently evict the fake here.
    $this->fakeWorkosResponses([]);

    expect(app(WorkosClientManager::class))->toBe($fake->apiKeys());
});

it('scopes the api-keys swap away from every other manager', function (): void {
    Authkit::fake(['api-keys']);

    // The one partial fake that swaps a SHARED contract rather than a
    // manager-specific binding must leave the rest of the surface real.
    expect(app(VaultManager::class))->not->toBeInstanceOf(VaultFake::class)
        ->and(app(InvitationManager::class))->not->toBeInstanceOf(InvitationsFake::class)
        ->and(app(GroupManager::class))->not->toBeInstanceOf(GroupsFake::class)
        ->and(app(PipesManager::class))->not->toBeInstanceOf(PipesFake::class)
        ->and(app(FgaChecker::class))->not->toBeInstanceOf(FgaFake::class);
});

it('rebuilds an already-resolved vault disk against the fake crypto', function (): void {
    config()->set('filesystems.disks.vault-test-inner', [
        'driver' => 'local',
        'root' => storage_path('framework/testing/vault-prefake'),
    ]);
    config()->set('filesystems.disks.vault', [
        'driver' => 'vault',
        'disk' => 'vault-test-inner',
    ]);

    // Resolve the disk BEFORE faking: the adapter captures the real crypto
    // at build time, so without the purge in fakeVault() this disk would
    // keep trying to reach WorkOS.
    Storage::disk('vault');

    Authkit::fake(['vault']);

    Storage::disk('vault')->put('note.txt', 'secret body');

    expect(Storage::disk('vault-test-inner')->get('note.txt'))->toStartWith(FakeVaultCrypto::MARKER)
        ->and(Storage::disk('vault')->get('note.txt'))->toBe('secret body');
});

it('starts every fake empty, isolated from other tests', function (): void {
    // If FgaFakeTest/AuditLogFakeTest state leaked across tests, this fresh
    // fake would already hold recorded calls.
    $fake = Authkit::fake();

    $fake->fga()->assertNothingChecked();
    $fake->auditLog()->assertNothingLogged();
    $fake->invitations()->assertNothingSent();
    $fake->apiKeys()->assertNothingCreated();
    $fake->vault()->assertNothingStored();
    $fake->organizationSync()->assertNothingSyncRequested();

    expect(Authkit::check('projects.view', '42', 'project', 'om_test'))->toBeFalse();
});
