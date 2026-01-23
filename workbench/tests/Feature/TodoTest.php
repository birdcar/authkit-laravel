<?php

declare(strict_types=1);

use App\Livewire\TodoItem;
use App\Livewire\TodoList;
use App\Models\Organization;
use App\Models\Todo;
use App\Models\User;
use Livewire\Livewire;

test('user can view todos page', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $user->organizations()->attach($org);

    $this->actingAs($user, 'workos')
        ->withSession(['current_organization_id' => $org->id])
        ->get('/todos')
        ->assertOk()
        ->assertSee('Todos');
});

test('user can create a todo', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $user->organizations()->attach($org);

    session(['current_organization_id' => $org->id]);

    Livewire::actingAs($user, 'workos')
        ->test(TodoList::class)
        ->set('newTodo', 'My new task')
        ->call('addTodo');

    $this->assertDatabaseHas('todos', [
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'title' => 'My new task',
        'completed' => false,
    ]);
});

test('user can toggle todo completion', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $user->organizations()->attach($org);
    $todo = Todo::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'completed' => false,
    ]);

    Livewire::actingAs($user, 'workos')
        ->test(TodoItem::class, ['todo' => $todo])
        ->call('toggle');

    expect($todo->fresh()->completed)->toBeTrue();
});

test('user can delete a todo', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $user->organizations()->attach($org);
    $todo = Todo::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    Livewire::actingAs($user, 'workos')
        ->test(TodoItem::class, ['todo' => $todo])
        ->call('confirmDelete')
        ->call('delete');

    $this->assertDatabaseMissing('todos', ['id' => $todo->id]);
});

test('todos are scoped to organization', function () {
    $user = User::factory()->create();
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();
    $user->organizations()->attach([$org1->id, $org2->id]);

    Todo::factory()->create(['user_id' => $user->id, 'organization_id' => $org1->id, 'title' => 'Org 1 Task']);
    Todo::factory()->create(['user_id' => $user->id, 'organization_id' => $org2->id, 'title' => 'Org 2 Task']);

    $this->actingAs($user, 'workos')
        ->withSession(['current_organization_id' => $org1->id])
        ->get('/todos')
        ->assertSee('Org 1 Task')
        ->assertDontSee('Org 2 Task');
});
