<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Organization;
use App\Models\Todo;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Component;
use WorkOS\AuthKit\Facades\WorkOS;

class TodoList extends Component
{
    public string $newTodo = '';

    public string $filter = 'all';

    protected $listeners = ['todoDeleted' => '$refresh'];

    public function addTodo(): void
    {
        $this->validate([
            'newTodo' => 'required|string|max:255',
        ]);

        $user = auth()->user();
        $organization = request()->attributes->get('current_organization')
            ?? (Session::has('current_organization_id')
                ? Organization::find(Session::get('current_organization_id'))
                : null);

        $todo = Todo::create([
            'user_id' => $user->id,
            'organization_id' => $organization?->id,
            'title' => $this->newTodo,
            'completed' => false,
        ]);

        // Audit log
        WorkOS::audit('todo.created', [
            ['type' => 'todo', 'id' => (string) $todo->id, 'name' => $todo->title],
        ]);

        $this->newTodo = '';
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    #[Computed]
    public function todos()
    {
        $user = auth()->user();
        $organization = request()->attributes->get('current_organization')
            ?? (Session::has('current_organization_id')
                ? Organization::find(Session::get('current_organization_id'))
                : null);

        $query = Todo::where('user_id', $user->id);

        if ($organization) {
            $query->where('organization_id', $organization->id);
        }

        return match ($this->filter) {
            'active' => $query->where('completed', false)->latest()->get(),
            'completed' => $query->where('completed', true)->latest()->get(),
            default => $query->latest()->get(),
        };
    }

    #[Computed]
    public function counts()
    {
        $user = auth()->user();
        $organization = request()->attributes->get('current_organization')
            ?? (Session::has('current_organization_id')
                ? Organization::find(Session::get('current_organization_id'))
                : null);

        $query = Todo::where('user_id', $user->id);
        if ($organization) {
            $query->where('organization_id', $organization->id);
        }

        return [
            'all' => (clone $query)->count(),
            'active' => (clone $query)->where('completed', false)->count(),
            'completed' => (clone $query)->where('completed', true)->count(),
        ];
    }

    public function render(): View
    {
        return view('livewire.todo-list');
    }
}
