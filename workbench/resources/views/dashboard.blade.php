<x-layouts.app>
    <x-slot name="title">Dashboard</x-slot>

    <flux:heading size="xl" level="1">Dashboard</flux:heading>

    <flux:subheading class="mb-6">
        Welcome back, {{ auth()->user()->name }}!
    </flux:subheading>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <flux:card>
            <flux:heading size="lg">Your Todos</flux:heading>
            <div class="mt-2">
                <p class="text-4xl font-bold text-zinc-900 dark:text-white">
                    {{ $todoCount ?? 0 }}
                </p>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Total tasks</p>
            </div>
        </flux:card>

        <flux:card>
            <flux:heading size="lg">Completed</flux:heading>
            <div class="mt-2">
                <p class="text-4xl font-bold text-green-600 dark:text-green-400">
                    {{ $completedCount ?? 0 }}
                </p>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Tasks done</p>
            </div>
        </flux:card>

        <flux:card>
            <flux:heading size="lg">Organization</flux:heading>
            <div class="mt-2">
                <p class="text-lg font-medium text-zinc-900 dark:text-white truncate">
                    {{ $currentOrganization?->name ?? 'Personal' }}
                </p>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ $memberCount ?? 0 }} members
                </p>
            </div>
        </flux:card>
    </div>

    <div class="mt-8">
        <flux:heading size="lg" class="mb-4">Quick Actions</flux:heading>

        <div class="flex gap-4">
            <flux:button href="{{ route('todos.index') }}" variant="primary">
                <flux:icon.plus class="mr-2" />
                New Todo
            </flux:button>

            <flux:button href="{{ route('organizations.settings') }}" variant="ghost">
                <flux:icon.cog-6-tooth class="mr-2" />
                Organization Settings
            </flux:button>
        </div>
    </div>
</x-layouts.app>
