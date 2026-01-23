<flux:card>
    <flux:heading size="lg">Admin Portal</flux:heading>
    <flux:text class="text-zinc-500 dark:text-zinc-400 mb-4">
        Configure enterprise features for your organization.
    </flux:text>

    @if ($organization)
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach ($intents as $intent => $config)
                @php $link = $links[$intent] ?? null; @endphp
                @if ($link)
                    <a
                        href="{{ $link }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="block p-4 border border-zinc-200 dark:border-zinc-700 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors"
                    >
                        <div class="flex items-start gap-3">
                            <div class="p-2 bg-zinc-100 dark:bg-zinc-700 rounded-lg">
                                @switch($config['icon'])
                                    @case('key')
                                        <flux:icon.key class="w-5 h-5 text-zinc-600 dark:text-zinc-300" />
                                        @break
                                    @case('users')
                                        <flux:icon.users class="w-5 h-5 text-zinc-600 dark:text-zinc-300" />
                                        @break
                                    @case('document-text')
                                        <flux:icon.document-text class="w-5 h-5 text-zinc-600 dark:text-zinc-300" />
                                        @break
                                    @case('arrow-trending-up')
                                        <flux:icon.arrow-trending-up class="w-5 h-5 text-zinc-600 dark:text-zinc-300" />
                                        @break
                                    @case('shield-check')
                                        <flux:icon.shield-check class="w-5 h-5 text-zinc-600 dark:text-zinc-300" />
                                        @break
                                    @case('document-check')
                                        <flux:icon.document-check class="w-5 h-5 text-zinc-600 dark:text-zinc-300" />
                                        @break
                                @endswitch
                            </div>
                            <div>
                                <flux:heading size="sm">{{ $config['label'] }}</flux:heading>
                                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ $config['description'] }}
                                </flux:text>
                            </div>
                            <flux:icon.arrow-top-right-on-square class="w-4 h-4 text-zinc-400 ml-auto" />
                        </div>
                    </a>
                @else
                    <div class="block p-4 border border-zinc-200 dark:border-zinc-700 rounded-lg opacity-50 cursor-not-allowed">
                        <div class="flex items-start gap-3">
                            <div class="p-2 bg-zinc-100 dark:bg-zinc-700 rounded-lg">
                                @switch($config['icon'])
                                    @case('key')
                                        <flux:icon.key class="w-5 h-5 text-zinc-600 dark:text-zinc-300" />
                                        @break
                                    @case('users')
                                        <flux:icon.users class="w-5 h-5 text-zinc-600 dark:text-zinc-300" />
                                        @break
                                    @case('document-text')
                                        <flux:icon.document-text class="w-5 h-5 text-zinc-600 dark:text-zinc-300" />
                                        @break
                                    @case('arrow-trending-up')
                                        <flux:icon.arrow-trending-up class="w-5 h-5 text-zinc-600 dark:text-zinc-300" />
                                        @break
                                    @case('shield-check')
                                        <flux:icon.shield-check class="w-5 h-5 text-zinc-600 dark:text-zinc-300" />
                                        @break
                                    @case('document-check')
                                        <flux:icon.document-check class="w-5 h-5 text-zinc-600 dark:text-zinc-300" />
                                        @break
                                @endswitch
                            </div>
                            <div>
                                <flux:heading size="sm">{{ $config['label'] }}</flux:heading>
                                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ $config['description'] }}
                                </flux:text>
                            </div>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    @else
        <flux:callout variant="warning" icon="exclamation-triangle">
            Admin Portal requires an organization. Please join or create an organization first.
        </flux:callout>
    @endif
</flux:card>
