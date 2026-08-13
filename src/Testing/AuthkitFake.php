<?php

declare(strict_types=1);

namespace Authkit\Authkit\Testing;

use Authkit\Authkit\AuditLogManager;
use Authkit\Authkit\Authkit;
use Authkit\Authkit\Authorization\FgaChecker;
use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Groups\GroupManager;
use Authkit\Authkit\Invitations\InvitationManager;
use Authkit\Authkit\Pipes\PipesManager;
use Authkit\Authkit\Testing\Fakes\ApiKeysFake;
use Authkit\Authkit\Testing\Fakes\AuditLogFake;
use Authkit\Authkit\Testing\Fakes\FgaFake;
use Authkit\Authkit\Testing\Fakes\GroupsFake;
use Authkit\Authkit\Testing\Fakes\InvitationsFake;
use Authkit\Authkit\Testing\Fakes\OrganizationSyncFake;
use Authkit\Authkit\Testing\Fakes\PipesFake;
use Authkit\Authkit\Testing\Fakes\VaultFake;
use Authkit\Authkit\Vault\VaultCrypto;
use Authkit\Authkit\Vault\VaultManager;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;

/**
 * The root testing handle returned by `Authkit::fake()`: swaps the named
 * managers' container bindings for in-memory fakes and exposes each fake
 * through a typed accessor for scripting and assertions.
 *
 * Deliberately NOT bound in the container and NOT swapped in as the facade
 * root: application code keeps calling the real {@see Authkit},
 * whose accessors resolve managers through the container — where the fakes
 * now live. That is what makes partial faking sound: after
 * `Authkit::fake(['fga'])`, `Authkit::vault()` still serves the REAL vault
 * manager on every code path, while `$fake->vault()` (a test-only accessor)
 * throws rather than handing back an assertion surface that records nothing.
 */
final class AuthkitFake
{
    /** Every manager fake() knows how to swap. */
    public const array MANAGERS = [
        'fga',
        'invitations',
        'audit-log',
        'organization-sync',
        'api-keys',
        'vault',
        'pipes',
        'groups',
    ];

    private ?FgaFake $fga = null;

    private ?InvitationsFake $invitations = null;

    private ?AuditLogFake $auditLog = null;

    private ?OrganizationSyncFake $organizationSync = null;

    private ?ApiKeysFake $apiKeys = null;

    private ?VaultFake $vault = null;

    private ?PipesFake $pipes = null;

    private ?GroupsFake $groups = null;

    /**
     * @param  list<string>|null  $managers  null fakes everything; a list fakes only those managers
     */
    public function __construct(?array $managers = null)
    {
        foreach ($managers ?? self::MANAGERS as $manager) {
            match ($manager) {
                'fga' => $this->fakeFga(),
                'invitations' => $this->fakeInvitations(),
                'audit-log' => $this->fakeAuditLog(),
                'organization-sync' => $this->fakeOrganizationSync(),
                'api-keys' => $this->fakeApiKeys(),
                'vault' => $this->fakeVault(),
                'pipes' => $this->fakePipes(),
                'groups' => $this->fakeGroups(),
                default => throw new InvalidArgumentException(sprintf(
                    'Unknown manager [%s] passed to Authkit::fake(). Valid managers: %s.',
                    $manager,
                    implode(', ', self::MANAGERS),
                )),
            };
        }
    }

    public function fga(): FgaFake
    {
        return $this->fga ?? $this->notFaked('fga');
    }

    public function invitations(): InvitationsFake
    {
        return $this->invitations ?? $this->notFaked('invitations');
    }

    public function auditLog(): AuditLogFake
    {
        return $this->auditLog ?? $this->notFaked('audit-log');
    }

    public function organizationSync(): OrganizationSyncFake
    {
        return $this->organizationSync ?? $this->notFaked('organization-sync');
    }

    public function apiKeys(): ApiKeysFake
    {
        return $this->apiKeys ?? $this->notFaked('api-keys');
    }

    public function vault(): VaultFake
    {
        return $this->vault ?? $this->notFaked('vault');
    }

    public function pipes(): PipesFake
    {
        return $this->pipes ?? $this->notFaked('pipes');
    }

    public function groups(): GroupsFake
    {
        return $this->groups ?? $this->notFaked('groups');
    }

    private function fakeFga(): void
    {
        app()->instance(FgaChecker::class, $this->fga = new FgaFake);
    }

    private function fakeInvitations(): void
    {
        app()->instance(InvitationManager::class, $this->invitations = new InvitationsFake);
    }

    private function fakeAuditLog(): void
    {
        app()->instance(AuditLogManager::class, $this->auditLog = new AuditLogFake);
    }

    private function fakeOrganizationSync(): void
    {
        // No container binding exists for org sync — it is the two observer-
        // dispatched jobs, captured at the Bus level by the fake itself.
        $this->organizationSync = new OrganizationSyncFake;
    }

    private function fakeApiKeys(): void
    {
        // The key surface (model traits + authkit-key guard) resolves the
        // client manager per call, so the swap point is the contract itself.
        //
        // Bound as a singleton CLOSURE, not an instance: the MockHandler test
        // harness (UsesWorkosMockHandler) calls forgetInstance() on this
        // contract after every fakeWorkosResponses(), which would silently
        // evict an instance() binding and fall back to the real client. A
        // closure survives forgetInstance — the next make() re-serves the
        // same fake. (singleton() itself drops any stale resolved instance.)
        $fake = $this->apiKeys = new ApiKeysFake;

        app()->singleton(WorkosClientManager::class, static fn (): ApiKeysFake => $fake);
    }

    private function fakeVault(): void
    {
        $this->vault = new VaultFake;

        app()->instance(VaultManager::class, $this->vault);
        app()->instance(VaultCrypto::class, $this->vault->crypto());

        // A vault-driver disk resolved BEFORE this swap captured the real
        // crypto at build time — purge those so their next use rebuilds
        // against the fake.
        foreach ((array) config('filesystems.disks', []) as $name => $disk) {
            if (is_string($name) && is_array($disk) && ($disk['driver'] ?? null) === 'vault') {
                Storage::forgetDisk($name);
            }
        }
    }

    private function fakePipes(): void
    {
        app()->instance(PipesManager::class, $this->pipes = new PipesFake);
    }

    private function fakeGroups(): void
    {
        app()->instance(GroupManager::class, $this->groups = new GroupsFake);
    }

    private function notFaked(string $manager): never
    {
        throw new LogicException(sprintf(
            'Manager [%s] is not faked — call Authkit::fake() (or Authkit::fake([\'%s\'])) before scripting '
            .'or asserting it. Unfaked managers keep their real behavior through the Authkit facade and the container.',
            $manager,
            $manager,
        ));
    }
}
