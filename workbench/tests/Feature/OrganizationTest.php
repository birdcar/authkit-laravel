<?php

declare(strict_types=1);

use App\Livewire\OrganizationSwitcher;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;
use WorkOS\AuthKit\WorkOS;

test('user can view organization settings', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $user->organizations()->attach($org, ['role' => 'admin']);

    WorkOS::fake()->actingAs($user);

    $this->withSession(['current_organization_id' => $org->id])
        ->get('/organizations/settings')
        ->assertOk();
})->afterEach(fn () => WorkOS::restore());

test('organization switcher shows all organizations', function () {
    $user = User::factory()->create();
    $org1 = Organization::factory()->create(['name' => 'Acme Corp']);
    $org2 = Organization::factory()->create(['name' => 'Globex Inc']);
    $user->organizations()->attach([$org1->id, $org2->id]);

    WorkOS::fake()->actingAs($user);

    Livewire::test(OrganizationSwitcher::class)
        ->assertSee('Acme Corp')
        ->assertSee('Globex Inc');
})->afterEach(fn () => WorkOS::restore());

test('user can switch organizations', function () {
    $user = User::factory()->create();
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();
    $user->organizations()->attach([$org1->id, $org2->id]);

    WorkOS::fake()->actingAs($user);
    session(['current_organization_id' => $org1->id]);

    Livewire::test(OrganizationSwitcher::class)
        ->call('switch', $org2->id);

    expect(session('current_organization_id'))->toBe($org2->id);
})->afterEach(fn () => WorkOS::restore());

test('members list shows organization users', function () {
    $user = User::factory()->create();
    $member = User::factory()->create(['name' => 'Jane Doe']);
    $org = Organization::factory()->create();
    $user->organizations()->attach($org, ['role' => 'admin']);
    $member->organizations()->attach($org, ['role' => 'member']);

    WorkOS::fake()->actingAs($user);

    $this->withSession(['current_organization_id' => $org->id])
        ->get('/organizations/settings')
        ->assertOk();
})->afterEach(fn () => WorkOS::restore());
