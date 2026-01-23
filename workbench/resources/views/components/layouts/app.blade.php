<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name', 'Todo App') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-white dark:bg-zinc-800">
    @impersonating
        <flux:callout variant="warning" icon="eye" class="rounded-none">
            You are currently impersonating this user.
            <x-slot name="actions">
                <flux:button size="sm" href="{{ route('logout') }}">End Session</flux:button>
            </x-slot>
        </flux:callout>
    @endimpersonating

    <flux:sidebar sticky stashable class="bg-zinc-50 dark:bg-zinc-900 border-r border-zinc-200 dark:border-zinc-700">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

        <flux:brand href="{{ route('dashboard') }}" logo="/images/workos-logo.svg" name="Todo App" class="px-2 dark:hidden" />
        <flux:brand href="{{ route('dashboard') }}" logo="/images/workos-logo-white.svg" name="Todo App" class="px-2 hidden dark:flex" />

        <flux:navlist variant="outline">
            <flux:navlist.item icon="home" href="{{ route('dashboard') }}" :current="request()->routeIs('dashboard')">Dashboard</flux:navlist.item>
            <flux:navlist.item icon="check-circle" href="{{ route('todos.index') }}" :current="request()->routeIs('todos.*')">Todos</flux:navlist.item>
        </flux:navlist>

        <flux:spacer />

        {{-- Organization Switcher - Added in Phase 3 --}}
        <livewire:organization-switcher />

        <flux:navlist variant="outline">
            <flux:navlist.item icon="cog-6-tooth" href="{{ route('organizations.settings') }}" :current="request()->routeIs('organizations.settings')">Settings</flux:navlist.item>
        </flux:navlist>

        <flux:dropdown position="top" align="start" class="max-lg:hidden">
            <flux:profile avatar="{{ auth()->user()->avatar_url }}" name="{{ auth()->user()->name }}" />

            <flux:menu>
                <flux:menu.item icon="arrow-right-start-on-rectangle" href="{{ route('logout') }}">Logout</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </flux:sidebar>

    <flux:header class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <flux:spacer />

        <flux:dropdown position="top" align="end">
            <flux:profile avatar="{{ auth()->user()->avatar_url }}" />

            <flux:menu>
                <flux:menu.item icon="arrow-right-start-on-rectangle" href="{{ route('logout') }}">Logout</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </flux:header>

    <flux:main>
        {{ $slot }}
    </flux:main>

    @fluxScripts
</body>
</html>
