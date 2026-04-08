<div class="{{ $this->themeClass() }} woswidgets-domain-list" style="{{ $this->themeStyles() }}">
    <div class="woswidgets-card">
        <div class="woswidgets-section-header">
            <h3 class="woswidgets-section-title">Domains</h3>
            <button
                wire:click="generateAddDomainLink"
                wire:loading.attr="disabled"
                wire:target="generateAddDomainLink"
                class="woswidgets-button woswidgets-save-button"
            >
                Add domain
            </button>
        </div>

        @if($loading)
            <div class="woswidgets-skeleton">
                @foreach(range(1, 3) as $i)
                    <div class="woswidgets-skeleton-line" style="width: {{ [80, 65, 75][($i - 1) % 3] }}%"></div>
                @endforeach
            </div>
        @elseif($error)
            <div class="woswidgets-error">
                <p>{{ $error }}</p>
                <button wire:click="loadDomains" class="woswidgets-button woswidgets-retry-button">Retry</button>
            </div>
        @elseif(empty($domains))
            <div class="woswidgets-empty-state">
                <p>No domains configured.</p>
            </div>
        @else
            <div class="woswidgets-domain-items">
                @foreach($domains as $domain)
                    @php
                        $statusMap = [
                            'verified' => 'active',
                            'pending' => 'pending',
                            'failed' => 'inactive',
                        ];
                        $statusClass = $statusMap[$domain['state'] ?? ''] ?? 'inactive';
                    @endphp
                    <div class="woswidgets-card-list-item">
                        <div class="woswidgets-domain-info">
                            <span class="woswidgets-domain-name">{{ $domain['domain'] ?? 'Unknown domain' }}</span>
                            <span class="woswidgets-status woswidgets-status--{{ $statusClass }}">
                                {{ ucfirst($domain['state'] ?? 'unknown') }}
                            </span>
                        </div>
                        <div class="woswidgets-domain-actions">
                            @if(($domain['state'] ?? '') !== 'verified')
                                <button
                                    wire:click="reverifyDomain('{{ $domain['id'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="reverifyDomain('{{ $domain['id'] }}')"
                                    class="woswidgets-button woswidgets-cancel-button"
                                >
                                    Reverify
                                </button>
                            @endif
                            <button
                                wire:click="removeDomain('{{ $domain['id'] }}')"
                                wire:loading.attr="disabled"
                                wire:target="removeDomain('{{ $domain['id'] }}')"
                                class="woswidgets-button woswidgets-destructive-button"
                            >
                                Remove
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

@script
<script>
    $wire.on('open-admin-portal-link', ({ url }) => {
        window.open(url, '_blank', 'noopener');
    });
</script>
@endscript
