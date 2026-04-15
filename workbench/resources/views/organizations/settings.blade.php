<x-layouts.app>
    <x-slot name="title">Organization Settings</x-slot>

    <flux:heading size="xl" level="1">Organization Settings</flux:heading>

    @if ($organization)
        <flux:subheading class="mb-6">
            Manage settings for {{ $organization->name }}
        </flux:subheading>

        <div class="space-y-8">
            <livewire:workos-settings />
            <livewire:workos-admin-portal />
            <livewire:workos-user-management />
        </div>
    @else
        <flux:callout variant="warning" icon="exclamation-triangle">
            You are not a member of any organization.
        </flux:callout>
    @endif
</x-layouts.app>
