<div class="{{ $this->themeClass() }} woswidgets-sso-connection-list" style="{{ $this->themeStyles() }}">
    <div class="woswidgets-card">
        <div class="woswidgets-section-header">
            <h3 class="woswidgets-section-title">SSO Connections</h3>
            <button
                wire:click="generateManageLink"
                wire:loading.attr="disabled"
                wire:target="generateManageLink"
                class="woswidgets-button woswidgets-save-button"
            >
                Manage
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
                <button wire:click="loadConnections" class="woswidgets-button woswidgets-retry-button">Retry</button>
            </div>
        @elseif(empty($connections))
            <div class="woswidgets-empty-state">
                <p>No SSO connections configured.</p>
            </div>
        @else
            <div class="woswidgets-connection-list">
                @foreach($connections as $connection)
                    @php
                        $statusMap = [
                            'active' => 'active',
                            'inactive' => 'inactive',
                            'draft' => 'pending',
                            'validating' => 'pending',
                        ];
                        $statusClass = $statusMap[$connection['state'] ?? ''] ?? 'inactive';
                    @endphp
                    <div class="woswidgets-card-list-item">
                        <div class="woswidgets-connection-info">
                            <span class="woswidgets-connection-name">{{ $connection['name'] ?? 'Unnamed Connection' }}</span>
                            <span class="woswidgets-connection-type">{{ $connection['type'] ?? '' }}</span>
                        </div>
                        <span class="woswidgets-status woswidgets-status--{{ $statusClass }}">
                            {{ ucfirst($connection['state'] ?? 'unknown') }}
                        </span>
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
