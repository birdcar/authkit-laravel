<?php

declare(strict_types=1);

use Authkit\Authkit\Authorization\FgaChecker;
use Authkit\Authkit\Authorization\ResourceTarget;
use Authkit\Authkit\Exceptions\MembershipNotResolvedException;
use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Testing\Fakes\FgaFake;
use PHPUnit\Framework\AssertionFailedError;
use Workbench\App\Models\Organization;
use Workbench\App\Models\Project;
use Workbench\Database\Factories\UserFactory;
use WorkOS\PaginatedResponse;

beforeEach(function (): void {
    $this->migratePackageDatabase();
});

function fgaFake(): FgaFake
{
    $fake = new FgaFake;

    app()->instance(FgaChecker::class, $fake);

    return $fake;
}

it('denies unscripted checks by default and records them', function (): void {
    $fake = fgaFake();

    expect(Authkit::check('projects.view', '42', 'project', 'om_test'))->toBeFalse();

    $fake->assertChecked('projects.view');
    expect($fake->recordedChecks())->toHaveCount(1)
        ->and($fake->recordedChecks()[0]['membership_id'])->toBe('om_test');
});

it('serves scripted allow and deny decisions', function (): void {
    $fake = fgaFake();

    $fake->allow('projects.view', '42', 'project')
        ->deny('projects.delete', '42', 'project');

    expect(Authkit::check('projects.view', '42', 'project', 'om_test'))->toBeTrue()
        ->and(Authkit::check('projects.delete', '42', 'project', 'om_test'))->toBeFalse()
        ->and(Authkit::check('projects.view', '43', 'project', 'om_test'))->toBeFalse();
});

it('scripts decisions from a WorkosResource model', function (): void {
    $fake = fgaFake();
    $project = Project::query()->createQuietly(['name' => 'Skunkworks', 'organization_id' => 'org_x']);

    $fake->allow('projects.view', $project);

    expect(Authkit::check('projects.view', $project->workosResourceExternalId(), 'project', 'om_test'))->toBeTrue();

    $fake->assertChecked('projects.view', $project);
});

it('lets a later script win for the same permission and resource', function (): void {
    $fake = fgaFake();

    $fake->allow('projects.view', '42', 'project')->deny('projects.view', '42', 'project');

    expect(Authkit::check('projects.view', '42', 'project', 'om_test'))->toBeFalse();
});

it('requires a type slug alongside a raw external id', function (): void {
    $fake = fgaFake();

    expect(fn (): FgaFake => $fake->allow('projects.view', '42'))
        ->toThrow(InvalidArgumentException::class, 'type slug');
});

it('throws without membership context exactly like production', function (): void {
    fgaFake();

    expect(fn (): bool => Authkit::check('projects.view', '42', 'project'))
        ->toThrow(MembershipNotResolvedException::class);
});

it('synthesizes a membership id from the acting session', function (): void {
    $fake = fgaFake();
    $user = UserFactory::new()->create(['workos_id' => 'user_acting']);
    $organization = Organization::query()->createQuietly(['name' => 'Acme', 'workos_id' => 'org_acting']);

    Authkit::actingAs($user, ['organization' => $organization]);

    expect(Authkit::check('projects.view', '42', 'project'))->toBeFalse();

    $recorded = $fake->recordedChecks();

    expect($recorded[0]['membership_id'])->toBe("om_fake_{$user->getKey()}_org_acting");
});

it('asserts checked, not checked, and nothing checked with readable failures', function (): void {
    $fake = fgaFake();

    $fake->assertNothingChecked();
    $fake->assertNotChecked('projects.view');

    expect(fn () => $fake->assertChecked('projects.view'))
        ->toThrow(AssertionFailedError::class, 'No checks were performed');

    Authkit::check('projects.view', '42', 'project', 'om_test');

    $fake->assertChecked('projects.view');
    $fake->assertChecked('projects.view', '42', 'project');
    $fake->assertNotChecked('projects.view', '43', 'project');

    expect(fn () => $fake->assertNothingChecked())
        ->toThrow(AssertionFailedError::class, 'Performed checks: [projects.view] on [ext:project:42] as [om_test]');
});

it('serves scripted list fixtures through the real return types', function (): void {
    $fake = fgaFake();

    $emptyResources = Authkit::fga()->listResourcesForMembership('om_test', ResourceTarget::byId('res_parent'), 'projects.view');

    expect($emptyResources)->toBeInstanceOf(PaginatedResponse::class)
        ->and($emptyResources->data)->toBe([]);

    $fake->scriptResourcesForMembership([['resource_id' => 'res_1']])
        ->scriptMembershipsForResource([['membership_id' => 'om_1'], ['membership_id' => 'om_2']]);

    expect(Authkit::fga()->listResourcesForMembership('om_test', ResourceTarget::byId('res_parent'), 'projects.view')->data)
        ->toHaveCount(1)
        ->and(Authkit::fga()->listMembershipsForResource('res_1', 'projects.view')->data)->toHaveCount(2)
        ->and(Authkit::fga()->listMembershipsForResourceByExternalId('org_x', 'project', '42', 'projects.view')->data)->toHaveCount(2);
});

it('keeps the facade wired to the fake through the container', function (): void {
    $fake = fgaFake();

    expect(Authkit::fga())->toBe($fake);
});
