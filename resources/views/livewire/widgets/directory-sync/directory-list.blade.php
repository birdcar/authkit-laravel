<div class="{{ $this->themeClass() }} woswidgets-directory-list" style="{{ $this->themeStyles() }}">
    <div class="woswidgets-card">
        <div class="woswidgets-section-header">
            <h3 class="woswidgets-section-title">Directory Sync</h3>
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
                <button wire:click="loadDirectories" class="woswidgets-button woswidgets-retry-button">Retry</button>
            </div>
        @elseif(empty($directories))
            <div class="woswidgets-empty-state">
                <p>No directories configured.</p>
            </div>
        @else
            <div class="woswidgets-directory-items">
                @foreach($directories as $directory)
                    @php
                        $statusMap = [
                            'linked' => 'active',
                            'active' => 'active',
                            'inactive' => 'inactive',
                            'deleting' => 'pending',
                            'validating' => 'pending',
                        ];
                        $statusClass = $statusMap[$directory['state'] ?? ''] ?? 'inactive';
                        $typeLabel = $this->typeLabel($directory['type'] ?? '');
                    @endphp
                    <div class="woswidgets-card-list-item">
                        <div class="woswidgets-directory-info">
                            <span class="woswidgets-directory-name">{{ $directory['name'] ?? 'Unnamed directory' }}</span>
                            <span class="woswidgets-directory-type">{{ $typeLabel }}</span>
                        </div>
                        <div class="woswidgets-directory-status-actions">
                            <span class="woswidgets-status woswidgets-status--{{ $statusClass }}">
                                {{ ucfirst($directory['state'] ?? 'unknown') }}
                            </span>
                            <button
                                wire:click="removeDirectory('{{ $directory['id'] }}')"
                                wire:loading.attr="disabled"
                                wire:target="removeDirectory('{{ $directory['id'] }}')"
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
