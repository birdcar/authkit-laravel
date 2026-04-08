<div class="{{ $this->themeClass() }} woswidgets-member-actions" style="{{ $this->themeStyles() }}">
    @if($loading)
        <div class="woswidgets-skeleton">
            <div class="woswidgets-skeleton-line" style="width: 60px"></div>
        </div>
    @elseif($error)
        <div class="woswidgets-error">
            <p>{{ $error }}</p>
        </div>
    @else
        @if($showRoleEditor)
            <div class="woswidgets-role-editor">
                <select
                    class="woswidgets-select"
                    wire:model="selectedRole"
                    aria-label="Select role"
                >
                    <option value="">Select a role</option>
                    @foreach($roles as $role)
                        <option value="{{ $role['slug'] }}">{{ $role['name'] }}</option>
                    @endforeach
                </select>
                <div class="woswidgets-role-editor-actions">
                    <button
                        wire:click="updateRole"
                        class="woswidgets-button woswidgets-save-button"
                        wire:loading.attr="disabled"
                        wire:target="updateRole"
                    >
                        Save
                    </button>
                    <button
                        wire:click="$set('showRoleEditor', false)"
                        class="woswidgets-button woswidgets-cancel-button"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        @elseif($showRemoveConfirm)
            <div class="woswidgets-remove-confirm">
                <p class="woswidgets-confirm-text">Remove this member?</p>
                <div class="woswidgets-confirm-actions">
                    <button
                        wire:click="removeMember"
                        class="woswidgets-button woswidgets-destructive-button"
                        wire:loading.attr="disabled"
                        wire:target="removeMember"
                    >
                        Remove
                    </button>
                    <button
                        wire:click="$set('showRemoveConfirm', false)"
                        class="woswidgets-button woswidgets-cancel-button"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        @else
            <div class="woswidgets-action-buttons">
                <button
                    wire:click="$set('showRoleEditor', true)"
                    class="woswidgets-button woswidgets-cancel-button"
                >
                    Edit role
                </button>
                <button
                    wire:click="$set('showRemoveConfirm', true)"
                    class="woswidgets-button woswidgets-destructive-button"
                >
                    Remove
                </button>
            </div>
        @endif
    @endif
</div>
