<x-layouts.app>
    <x-slot name="title">Todos</x-slot>

    <div class="max-w-2xl mx-auto">
        <flux:heading size="xl" level="1" class="mb-6">
            Todos
            @if ($currentOrganization)
                <flux:badge variant="outline" class="ml-2">{{ $currentOrganization->name }}</flux:badge>
            @endif
        </flux:heading>

        <livewire:todo-list />
    </div>
</x-layouts.app>
