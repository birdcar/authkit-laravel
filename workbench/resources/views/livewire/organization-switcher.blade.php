<div>
    @if ($organizations->count() > 0)
        <flux:dropdown position="top" align="start">
            <flux:button variant="ghost" class="w-full justify-start">
                <flux:icon.building-office class="mr-2" />
                <span class="truncate">{{ $current?->name ?? 'Select Organization' }}</span>
                <flux:icon.chevron-up-down class="ml-auto" />
            </flux:button>

            <flux:menu>
                @foreach ($organizations as $org)
                    <flux:menu.item
                        wire:click="switch({{ $org->id }})"
                        :active="$current?->id === $org->id"
                    >
                        {{ $org->name }}
                        @if ($current?->id === $org->id)
                            <flux:icon.check class="ml-auto text-green-500" />
                        @endif
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
    @else
        <div class="px-3 py-2 text-sm text-zinc-500 dark:text-zinc-400">
            No organizations
        </div>
    @endif
</div>
