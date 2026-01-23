<x-layouts.guest>
    <x-slot name="title">Login</x-slot>

    <div class="w-full max-w-sm">
        <div class="text-center mb-8">
            <img src="/images/workos-logo.svg" alt="WorkOS" class="h-8 mx-auto mb-4 dark:hidden">
            <img src="/images/workos-logo-white.svg" alt="WorkOS" class="h-8 mx-auto mb-4 hidden dark:block">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Todo App</h1>
            <p class="text-zinc-600 dark:text-zinc-400 mt-2">Sign in to manage your todos</p>
        </div>

        @if (session('error'))
            <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6">
                {{ session('error') }}
            </flux:callout>
        @endif

        <flux:card>
            <div class="space-y-4">
                <flux:button href="{{ route('login') }}" variant="primary" class="w-full">
                    <flux:icon.arrow-right-end-on-rectangle class="mr-2" />
                    Sign in with WorkOS
                </flux:button>

                <div class="text-center text-sm text-zinc-500 dark:text-zinc-400">
                    Don't have an account?
                    <flux:link href="{{ route('login') }}">Sign up</flux:link>
                </div>
            </div>
        </flux:card>
    </div>
</x-layouts.guest>
