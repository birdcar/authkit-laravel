<div>
    {{-- Add Todo Form --}}
    <form wire:submit="addTodo" class="mb-6">
        <div class="flex gap-3">
            <flux:input
                wire:model="newTodo"
                placeholder="What needs to be done?"
                class="flex-1"
            />
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                <flux:icon.plus class="mr-2" wire:loading.remove wire:target="addTodo" />
                <flux:icon.arrow-path class="mr-2 animate-spin" wire:loading wire:target="addTodo" />
                Add
            </flux:button>
        </div>
        @error('newTodo')
            <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text>
        @enderror
    </form>

    {{-- Filters --}}
    <div class="flex gap-2 mb-4">
        <flux:button
            wire:click="setFilter('all')"
            :variant="$filter === 'all' ? 'primary' : 'ghost'"
            size="sm"
        >
            All ({{ $this->counts['all'] }})
        </flux:button>
        <flux:button
            wire:click="setFilter('active')"
            :variant="$filter === 'active' ? 'primary' : 'ghost'"
            size="sm"
        >
            Active ({{ $this->counts['active'] }})
        </flux:button>
        <flux:button
            wire:click="setFilter('completed')"
            :variant="$filter === 'completed' ? 'primary' : 'ghost'"
            size="sm"
        >
            Completed ({{ $this->counts['completed'] }})
        </flux:button>
    </div>

    {{-- Todo List --}}
    <div class="space-y-2" wire:loading.class="opacity-50">
        @forelse ($this->todos as $todo)
            <livewire:todo-item :todo="$todo" :key="$todo->id" />
        @empty
            <flux:card class="text-center py-8">
                <flux:icon.clipboard-document-list class="w-12 h-12 mx-auto text-zinc-400 mb-3" />
                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    @if ($filter === 'completed')
                        No completed todos yet.
                    @elseif ($filter === 'active')
                        All caught up! No active todos.
                    @else
                        No todos yet. Add one above!
                    @endif
                </flux:text>
            </flux:card>
        @endforelse
    </div>
</div>
