<?php

declare(strict_types=1);

use Authkit\Authkit\AuditLogManager;
use Authkit\Authkit\Authorization\FgaChecker;
use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Groups\GroupManager;
use Authkit\Authkit\Invitations\InvitationManager;
use Authkit\Authkit\Pipes\PipesManager;
use Authkit\Authkit\Vault\VaultCrypto;
use Authkit\Authkit\Vault\VaultManager;
use Illuminate\Contracts\Auth\Authenticatable;
use WorkOS\RequestOptions;

/**
 * The fake seam itself. Every manager a consumer can fake has to be
 * subclassable, because Authkit's accessors declare concrete return types
 * (Authkit::fga(): FgaChecker) — a fake that is not an instance of the real
 * class cannot be bound into the container at all.
 *
 * Re-adding `final` to any class below silently breaks every consumer test
 * that fakes it, with a TypeError far from the cause. That is what this file
 * exists to catch.
 */
$swappableManagers = [
    FgaChecker::class,
    InvitationManager::class,
    AuditLogManager::class,
    VaultManager::class,
    VaultCrypto::class,
    PipesManager::class,
    GroupManager::class,
];

it('keeps every fakeable manager open for subclassing', function (string $manager): void {
    expect(new ReflectionClass($manager))->isFinal()->toBeFalse();
})->with($swappableManagers);

it('resolves a subclass of a manager through the container', function (): void {
    $fake = new class extends FgaChecker
    {
        public function __construct() {}

        public function check(
            string $permissionSlug,
            string $resourceExternalId,
            string $resourceTypeSlug,
            ?string $organizationMembershipId = null,
            ?Authenticatable $user = null,
            ?string $organizationId = null,
            ?RequestOptions $options = null,
        ): bool {
            return true;
        }
    };

    app()->instance(FgaChecker::class, $fake);

    expect(Authkit::fga())->toBe($fake)
        ->and(Authkit::check('projects.view', 'proj_1', 'project'))->toBeTrue();
});
