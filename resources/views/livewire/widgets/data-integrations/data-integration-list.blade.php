<div class="{{ $this->themeClass() }} woswidgets-data-integration-list" style="{{ $this->themeStyles() }}">
    <div class="woswidgets-card">
        <div class="woswidgets-section-header">
            <h3 class="woswidgets-section-title">Data Integrations</h3>
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
                <button wire:click="loadIntegrations" class="woswidgets-button woswidgets-retry-button">Retry</button>
            </div>
        @elseif(empty($integrations))
            <div class="woswidgets-empty-state">
                <p>No data integrations installed.</p>
            </div>
        @else
            <div class="woswidgets-integration-items">
                @foreach($integrations as $integration)
                    @php
                        $statusMap = [
                            'active' => 'active',
                            'inactive' => 'inactive',
                            'pending' => 'pending',
                            'error' => 'inactive',
                        ];
                        $statusClass = $statusMap[$integration['status'] ?? ''] ?? 'inactive';
                    @endphp
                    <div class="woswidgets-card-list-item">
                        <div class="woswidgets-integration-info">
                            <span class="woswidgets-integration-name">{{ $integration['name'] ?? $integration['slug'] ?? 'Unknown' }}</span>
                            <span class="woswidgets-status woswidgets-status--{{ $statusClass }}">
                                {{ ucfirst($integration['status'] ?? 'unknown') }}
                            </span>
                        </div>
                        <div class="woswidgets-integration-actions">
                            @if(($integration['status'] ?? '') !== 'active' && !empty($integration['slug']))
                                <button
                                    wire:click="authorize('{{ $integration['slug'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="authorize('{{ $integration['slug'] }}')"
                                    class="woswidgets-button woswidgets-save-button"
                                >
                                    Authorize
                                </button>
                            @endif
                            @if(!empty($integration['installationId']))
                                <button
                                    wire:click="removeInstallation('{{ $integration['installationId'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="removeInstallation('{{ $integration['installationId'] }}')"
                                    class="woswidgets-button woswidgets-destructive-button"
                                >
                                    Remove
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

@script
<script>
    $wire.on('open-integration-auth-url', ({ url, dataIntegrationId, state }) => {
        window.open(url, '_blank', 'noopener');

        if (dataIntegrationId && state) {
            let attempts = 0;
            const maxAttempts = 12;
            const interval = setInterval(() => {
                attempts++;
                $wire.call('checkAuthStatus', dataIntegrationId, state);

                if (attempts >= maxAttempts) {
                    clearInterval(interval);
                }
            }, 5000);

            $wire.on('integration-installed', () => clearInterval(interval));
        }
    });
</script>
@endscript
