<div>
    <flux:card class="flex items-center gap-3 p-3 {{ $todo->completed ? 'opacity-60' : '' }}">
        <flux:checkbox
            wire:click="toggle"
            :checked="$todo->completed"
        />

        <span class="flex-1 {{ $todo->completed ? 'line-through text-zinc-500' : '' }}">
            {{ $todo->title }}
        </span>

        <flux:text class="text-xs text-zinc-400">
            {{ $todo->created_at->diffForHumans() }}
        </flux:text>

        <flux:button
            wire:click="confirmDelete"
            variant="ghost"
            size="sm"
            class="text-red-500 hover:text-red-600"
        >
            <flux:icon.trash class="w-4 h-4" />
        </flux:button>
    </flux:card>

    {{-- Delete Confirmation Modal --}}
    <flux:modal wire:model="confirmingDelete" class="max-w-md">
        <flux:heading size="lg">Delete Todo?</flux:heading>

        <flux:text class="mt-2 text-zinc-600 dark:text-zinc-400">
            Are you sure you want to delete "{{ $todo->title }}"? This action cannot be undone.
        </flux:text>

        <div class="flex justify-end gap-3 mt-6">
            <flux:button wire:click="cancelDelete" variant="ghost">
                Cancel
            </flux:button>
            <flux:button wire:click="delete" variant="danger">
                Delete
            </flux:button>
        </div>
    </flux:modal>
</div>
