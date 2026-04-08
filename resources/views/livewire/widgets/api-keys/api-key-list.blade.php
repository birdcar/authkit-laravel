<div class="{{ $this->themeClass() }} woswidgets-api-key-list" style="{{ $this->themeStyles() }}">
    <div class="woswidgets-card">
        <div class="woswidgets-section-header">
            <h3 class="woswidgets-section-title">API Keys</h3>
            @if(!$showCreateForm)
                <button
                    wire:click="$set('showCreateForm', true)"
                    class="woswidgets-button woswidgets-save-button"
                >
                    Create key
                </button>
            @endif
        </div>

        {{-- New key secret banner --}}
        @if($newKeyValue)
            <div class="woswidgets-api-key-secret">
                <p class="woswidgets-api-key-secret-warning">
                    Copy this key now. It will not be shown again.
                </p>
                <div class="woswidgets-api-key-secret-value">
                    <code>{{ $newKeyValue }}</code>
                    <button
                        x-data
                        x-on:click="navigator.clipboard.writeText(@js($newKeyValue))"
                        class="woswidgets-button woswidgets-cancel-button"
                    >
                        Copy
                    </button>
                </div>
                <button
                    wire:click="dismissNewKey"
                    class="woswidgets-button woswidgets-cancel-button"
                >
                    Dismiss
                </button>
            </div>
        @endif

        {{-- Create form --}}
        @if($showCreateForm)
            <div class="woswidgets-api-key-create-form">
                <div class="woswidgets-form-field">
                    <label class="woswidgets-form-label">Key name</label>
                    <input
                        type="text"
                        wire:model="newKeyName"
                        class="woswidgets-text-field"
                        placeholder="My API key"
                    />
                </div>

                @if(!empty($permissions))
                    <div class="woswidgets-form-field">
                        <label class="woswidgets-form-label">Permissions</label>
                        <div class="woswidgets-permissions-list">
                            @foreach($permissions as $permission)
                                <label class="woswidgets-checkbox-label">
                                    <input
                                        type="checkbox"
                                        wire:model="selectedPermissions"
                                        value="{{ $permission['slug'] ?? $permission['id'] ?? '' }}"
                                    />
                                    {{ $permission['name'] ?? $permission['slug'] ?? '' }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="woswidgets-form-actions">
                    <button
                        wire:click="createKey"
                        wire:loading.attr="disabled"
                        wire:target="createKey"
                        class="woswidgets-button woswidgets-save-button"
                    >
                        Create
                    </button>
                    <button
                        wire:click="$set('showCreateForm', false)"
                        class="woswidgets-button woswidgets-cancel-button"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        @endif

        @if($loading)
            <div class="woswidgets-skeleton">
                @foreach(range(1, 3) as $i)
                    <div class="woswidgets-skeleton-line" style="width: {{ [80, 65, 75][($i - 1) % 3] }}%"></div>
                @endforeach
            </div>
        @elseif($error)
            <div class="woswidgets-error">
                <p>{{ $error }}</p>
                <button wire:click="loadApiKeys" class="woswidgets-button woswidgets-retry-button">Retry</button>
            </div>
        @elseif(empty($apiKeys))
            <div class="woswidgets-empty-state">
                <p>No API keys created yet.</p>
            </div>
        @else
            <div class="woswidgets-api-key-items">
                @foreach($apiKeys as $key)
                    <div class="woswidgets-card-list-item">
                        <div class="woswidgets-api-key-info">
                            <span class="woswidgets-api-key-name">{{ $key['name'] ?? 'Unnamed key' }}</span>
                            <code class="woswidgets-api-key-obfuscated">{{ $key['obfuscatedValue'] ?? '••••••••' }}</code>
                        </div>
                        <button
                            wire:click="revokeKey('{{ $key['id'] }}')"
                            wire:loading.attr="disabled"
                            wire:target="revokeKey('{{ $key['id'] }}')"
                            class="woswidgets-button woswidgets-destructive-button"
                        >
                            Revoke
                        </button>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
