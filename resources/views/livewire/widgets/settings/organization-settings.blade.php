<div class="{{ $this->themeClass() }} woswidgets-organization-settings" style="{{ $this->themeStyles() }}">
    <div class="woswidgets-card">
        <div class="woswidgets-section-header">
            <h3 class="woswidgets-section-title">Settings</h3>
        </div>

        @if($loading)
            <div class="woswidgets-skeleton">
                @foreach(range(1, 4) as $i)
                    <div class="woswidgets-skeleton-line" style="width: {{ [60, 80, 70, 50][($i - 1) % 4] }}%"></div>
                @endforeach
            </div>
        @elseif($error)
            <div class="woswidgets-error">
                <p>{{ $error }}</p>
                <button wire:click="loadSettings" class="woswidgets-button woswidgets-retry-button">Retry</button>
            </div>
        @elseif(empty($settings))
            <div class="woswidgets-empty-state">
                <p>No settings available.</p>
            </div>
        @else
            <div class="woswidgets-settings-items">
                @foreach($settings as $key => $value)
                    @if(!is_array($value))
                        <div class="woswidgets-card-list-item">
                            <span class="woswidgets-settings-key">{{ ucwords(str_replace(['_', '-'], ' ', (string) $key)) }}</span>
                            <span class="woswidgets-settings-value">{{ is_bool($value) ? ($value ? 'Enabled' : 'Disabled') : $value }}</span>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
</div>
