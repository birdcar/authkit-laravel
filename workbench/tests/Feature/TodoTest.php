<?php

declare(strict_types=1);

use App\Livewire\TodoItem;
use App\Livewire\TodoList;
use App\Models\Organization;
use App\Models\Todo;
use App\Models\User;
use Livewire\Livewire;
use WorkOS\AuthKit\WorkOS;

test('user can view todos page', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $user->organizations()->attach($org);

    WorkOS::fake()->actingAs($user, permissions: ['todos.read']);

    $this->withSession(['current_organization_id' => $org->id])
        ->get('/todos')
        ->assertOk()
        ->assertSee('Todos');
})->afterEach(fn () => WorkOS::restore());

test('user can create a todo', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $user->organizations()->attach($org);

    $fake = WorkOS::fake()->actingAs($user, permissions: ['todos.read']);
    session(['current_organization_id' => $org->id]);

    Livewire::actingAs($user)->test(TodoList::class)
        ->set('newTodo', 'My new task')
        ->call('addTodo');

    $this->assertDatabaseHas('todos', [
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'title' => 'My new task',
        'completed' => false,
    ]);

    $fake->assertAudited('todo.created');
})->afterEach(fn () => WorkOS::restore());

test('user can toggle todo completion', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $user->organizations()->attach($org);
    $todo = Todo::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'completed' => false,
    ]);

    $fake = WorkOS::fake()->actingAs($user);

    Livewire::test(TodoItem::class, ['todo' => $todo])
        ->call('toggle');

    expect($todo->fresh()->completed)->toBeTrue();
    $fake->assertAudited('todo.completed');
})->afterEach(fn () => WorkOS::restore());

test('user can delete a todo', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $user->organizations()->attach($org);
    $todo = Todo::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $fake = WorkOS::fake()->actingAs($user);

    Livewire::test(TodoItem::class, ['todo' => $todo])
        ->call('confirmDelete')
        ->call('delete');

    $this->assertDatabaseMissing('todos', ['id' => $todo->id]);
    $fake->assertAudited('todo.deleted');
})->afterEach(fn () => WorkOS::restore());

test('todos are scoped to organization', function () {
    $user = User::factory()->create();
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();
    $user->organizations()->attach([$org1->id, $org2->id]);

    Todo::factory()->create(['user_id' => $user->id, 'organization_id' => $org1->id, 'title' => 'Org 1 Task']);
    Todo::factory()->create(['user_id' => $user->id, 'organization_id' => $org2->id, 'title' => 'Org 2 Task']);

    WorkOS::fake()->actingAs($user, permissions: ['todos.read']);

    $this->withSession(['current_organization_id' => $org1->id])
        ->get('/todos')
        ->assertSee('Org 1 Task')
        ->assertDontSee('Org 2 Task');
})->afterEach(fn () => WorkOS::restore());

test('admin can delete any todo via route', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $user->organizations()->attach($org);
    $todo = Todo::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $fake = WorkOS::fake()->actingAs($user, roles: ['admin']);

    $this->withSession(['current_organization_id' => $org->id])
        ->delete("/todos/{$todo->id}")
        ->assertOk();

    $this->assertDatabaseMissing('todos', ['id' => $todo->id]);
    $fake->assertAudited('todo.deleted');
})->afterEach(fn () => WorkOS::restore());

test('non-admin cannot delete todo via route', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $user->organizations()->attach($org);
    $todo = Todo::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    WorkOS::fake()->actingAs($user, roles: ['member']);

    $this->withSession(['current_organization_id' => $org->id])
        ->delete("/todos/{$todo->id}")
        ->assertForbidden();

    $this->assertDatabaseHas('todos', ['id' => $todo->id]);
})->afterEach(fn () => WorkOS::restore());
