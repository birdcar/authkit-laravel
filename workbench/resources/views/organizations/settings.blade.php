<x-layouts.app>
    <x-slot name="title">Organization Settings</x-slot>

    <flux:heading size="xl" level="1">Organization Settings</flux:heading>

    @if ($organization)
        <flux:subheading class="mb-6">
            Manage settings for {{ $organization->name }}
        </flux:subheading>

        <div class="space-y-8">
            {{-- Organization Info --}}
            <flux:card>
                <flux:heading size="lg">Organization Details</flux:heading>

                <div class="mt-4 space-y-3">
                    <div>
                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Name</flux:text>
                        <flux:text class="font-medium">{{ $organization->name }}</flux:text>
                    </div>

                    <div>
                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Slug</flux:text>
                        <flux:text class="font-mono">{{ $organization->slug }}</flux:text>
                    </div>

                    @if ($organization->domains)
                        <div>
                            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Domains</flux:text>
                            <div class="flex flex-wrap gap-2 mt-1">
                                @foreach ($organization->domains as $domain)
                                    <flux:badge>{{ $domain }}</flux:badge>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </flux:card>

            {{-- Admin Portal --}}
            <livewire:admin-portal-links :organization="$organization" />

            {{-- Members --}}
            <flux:card>
                <flux:heading size="lg">Members</flux:heading>

                <div class="mt-4">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Name</flux:table.column>
                            <flux:table.column>Email</flux:table.column>
                            <flux:table.column>Role</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($members as $member)
                                <flux:table.row>
                                    <flux:table.cell>
                                        <div class="flex items-center gap-3">
                                            <flux:avatar src="{{ $member->avatar_url }}" size="sm" />
                                            {{ $member->name }}
                                        </div>
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $member->email }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge variant="{{ $member->pivot->role === 'admin' ? 'primary' : 'default' }}">
                                            {{ ucfirst($member->pivot->role ?? 'member') }}
                                        </flux:badge>
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="3" class="text-center text-zinc-500">
                                        No members found
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            </flux:card>
        </div>
    @else
        <flux:callout variant="warning" icon="exclamation-triangle">
            You are not a member of any organization.
        </flux:callout>
    @endif
</x-layouts.app>
