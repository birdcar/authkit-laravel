<?php

declare(strict_types=1);

use Authkit\Authkit\Vault\DefaultVaultKeyContextResolver;
use Illuminate\Database\Eloquent\Model;

// Pure resolver logic — no MockHandler, no I/O. The key context governs which
// KEK wraps the data key at encrypt time; a wrong context is a SILENT
// cross-tenant key-sharing failure (decryption still succeeds), so this unit
// coverage is the only thing that catches context drift (spec-phase-9 §8).

final class VaultBareModel extends Model {}

final class VaultOrgAwareModel extends Model
{
    public function workosOrganizationId(): ?string
    {
        return 'org_01HXAMPLE';
    }
}

final class VaultOrglessModel extends Model
{
    public function workosOrganizationId(): ?string
    {
        return null;
    }
}

final class VaultOverrideModel extends Model
{
    /**
     * @return array<string, mixed>
     */
    public function vaultKeyContext(string $attribute): array
    {
        return [
            'model' => 'custom-owner',
            'purpose' => 'testing-'.$attribute,
            'tenant' => 42,
        ];
    }
}

describe('Vault', function (): void {
    describe('VaultKeyContext', function (): void {
        it('resolves the base model and attribute context for a bare model', function (): void {
            $context = (new DefaultVaultKeyContextResolver)->resolve(new VaultBareModel, 'secret');

            expect($context)->toBe([
                'model' => VaultBareModel::class,
                'attribute' => 'secret',
            ]);
        });

        it('adds organization_id when the model duck-types workosOrganizationId()', function (): void {
            $context = (new DefaultVaultKeyContextResolver)->resolve(new VaultOrgAwareModel, 'secret');

            expect($context)->toBe([
                'model' => VaultOrgAwareModel::class,
                'attribute' => 'secret',
                'organization_id' => 'org_01HXAMPLE',
            ]);
        });

        it('omits the organization_id key entirely when workosOrganizationId() returns null', function (): void {
            $context = (new DefaultVaultKeyContextResolver)->resolve(new VaultOrglessModel, 'secret');

            expect($context)->not->toHaveKey('organization_id')
                ->and($context)->toBe([
                    'model' => VaultOrglessModel::class,
                    'attribute' => 'secret',
                ]);
        });

        it('lets a vaultKeyContext() override win while keeping base keys it does not redefine', function (): void {
            $context = (new DefaultVaultKeyContextResolver)->resolve(new VaultOverrideModel, 'api_token');

            expect($context)->toBe([
                'model' => 'custom-owner',          // override wins over the base key
                'attribute' => 'api_token',         // base key survives — the override omitted it
                'purpose' => 'testing-api_token',
                'tenant' => '42',                   // scalar stringified, not dropped (context is array<string, string>)
            ]);
        });
    });
});
