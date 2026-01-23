<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Todo;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use WorkOS\AuthKit\Facades\WorkOS;

class TodoItem extends Component
{
    public Todo $todo;

    public bool $confirmingDelete = false;

    public function toggle(): void
    {
        $this->todo->completed = ! $this->todo->completed;
        $this->todo->save();

        $action = $this->todo->completed ? 'todo.completed' : 'todo.uncompleted';
        WorkOS::audit($action, [
            ['type' => 'todo', 'id' => (string) $this->todo->id, 'name' => $this->todo->title],
        ]);
    }

    public function confirmDelete(): void
    {
        $this->confirmingDelete = true;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDelete = false;
    }

    public function delete(): void
    {
        $todoId = $this->todo->id;
        $todoTitle = $this->todo->title;

        $this->todo->delete();

        WorkOS::audit('todo.deleted', [
            ['type' => 'todo', 'id' => (string) $todoId, 'name' => $todoTitle],
        ]);

        $this->dispatch('todoDeleted');
    }

    public function render(): View
    {
        return view('livewire.todo-item');
    }
}
