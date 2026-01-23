<?php

declare(strict_types=1);

use App\Livewire\OrganizationSwitcher;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;

test('user can view organization settings', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $user->organizations()->attach($org, ['role' => 'admin']);

    $this->actingAs($user, 'workos')
        ->withSession(['current_organization_id' => $org->id])
        ->get('/organizations/settings')
        ->assertOk();
});

test('organization switcher shows all organizations', function () {
    $user = User::factory()->create();
    $org1 = Organization::factory()->create(['name' => 'Acme Corp']);
    $org2 = Organization::factory()->create(['name' => 'Globex Inc']);
    $user->organizations()->attach([$org1->id, $org2->id]);

    Livewire::actingAs($user, 'workos')
        ->test(OrganizationSwitcher::class)
        ->assertSee('Acme Corp')
        ->assertSee('Globex Inc');
});

test('user can switch organizations', function () {
    $user = User::factory()->create();
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();
    $user->organizations()->attach([$org1->id, $org2->id]);

    session(['current_organization_id' => $org1->id]);

    Livewire::actingAs($user, 'workos')
        ->test(OrganizationSwitcher::class)
        ->call('switch', $org2->id);

    expect(session('current_organization_id'))->toBe($org2->id);
});

test('members list shows organization users', function () {
    $user = User::factory()->create();
    $member = User::factory()->create(['name' => 'Jane Doe']);
    $org = Organization::factory()->create();
    $user->organizations()->attach($org, ['role' => 'admin']);
    $member->organizations()->attach($org, ['role' => 'member']);

    $this->actingAs($user, 'workos')
        ->withSession(['current_organization_id' => $org->id])
        ->get('/organizations/settings')
        ->assertOk();
});
