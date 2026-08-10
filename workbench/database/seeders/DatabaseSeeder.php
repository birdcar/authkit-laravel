<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Workbench\App\Models\VaultDemoRecord;
use Workbench\Database\Factories\OrganizationFactory;
use Workbench\Database\Factories\PostFactory;
use Workbench\Database\Factories\UserFactory;

class DatabaseSeeder extends Seeder
{
    /**
     * WithoutModelEvents keeps seeding offline-safe: HasWorkosOrganization's
     * create-observer would otherwise call WorkOS (or a running emulator) for
     * every seeded organization. The workos_id linkage instead happens the
     * first time a login carries the org claim (the Login-event projection
     * listener) or the events poller catches up — exactly the projection
     * story the package documents.
     */
    use WithoutModelEvents;

    /**
     * One coherent seed story shared by `composer serve` browsing and the
     * emulate acceptance seed (tests/Fixtures/workos-emulate-acceptance.config.yaml):
     * the primary user's email matches the seeded emulator identity, so
     * logging in through a locally-running emulator links THIS row rather
     * than creating a parallel one.
     */
    public function run(): void
    {
        $primary = UserFactory::new()->create([
            'name' => 'Ada Acceptance',
            'email' => 'acceptance-trial@example.test',
        ]);

        UserFactory::new()->times(2)->create();

        OrganizationFactory::new()->create([
            'name' => 'Acceptance Org',
            // workos_id deliberately absent — see the class docblock.
        ]);

        PostFactory::new()->times(3)->create();

        // A Vaulted-cast fixture row for eyeballing `/demo/vault` behavior.
        // The secret is seeded as a plain DB write (events muted, cast still
        // applies on save — but Vault encryption requires live WorkOS/emulate
        // credentials, so the demo route creates its own row at request time
        // instead of relying on this one).
        VaultDemoRecord::query()->create(['secret' => null]);

        $this->command?->info(sprintf(
            'Seeded primary user #%s (acceptance-trial@example.test), 2 extra users, 1 organization, 3 posts.',
            $primary->getKey(),
        ));
    }
}
