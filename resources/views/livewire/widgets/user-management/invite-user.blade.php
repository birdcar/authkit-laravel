<div class="{{ $this->themeClass() }} woswidgets-invite-user" style="{{ $this->themeStyles() }}">
    <button
        wire:click="openModal"
        class="woswidgets-button woswidgets-save-button"
    >
        Invite member
    </button>

    @if($open)
        <div class="woswidgets-dialog-overlay" role="dialog" aria-modal="true" aria-labelledby="invite-dialog-title">
            <div class="woswidgets-dialog">
                <h2 id="invite-dialog-title" class="woswidgets-dialog-title">Invite a member</h2>

                <form wire:submit="sendInvite" class="woswidgets-invite-form">
                    <div class="woswidgets-form-field">
                        <label for="invite-email" class="woswidgets-label">Email <span aria-hidden="true">*</span></label>
                        <input
                            id="invite-email"
                            type="email"
                            class="woswidgets-text-field"
                            wire:model="email"
                            placeholder="member@example.com"
                            required
                            autocomplete="email"
                        />
                        @error('email')
                            <span class="woswidgets-field-error" role="alert">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="woswidgets-form-row">
                        <div class="woswidgets-form-field">
                            <label for="invite-first-name" class="woswidgets-label">First name</label>
                            <input
                                id="invite-first-name"
                                type="text"
                                class="woswidgets-text-field"
                                wire:model="firstName"
                                placeholder="First name"
                                autocomplete="given-name"
                            />
                        </div>
                        <div class="woswidgets-form-field">
                            <label for="invite-last-name" class="woswidgets-label">Last name</label>
                            <input
                                id="invite-last-name"
                                type="text"
                                class="woswidgets-text-field"
                                wire:model="lastName"
                                placeholder="Last name"
                                autocomplete="family-name"
                            />
                        </div>
                    </div>

                    @if(count($roles) > 0)
                        <div class="woswidgets-form-field">
                            <label for="invite-role" class="woswidgets-label">Role</label>
                            <select
                                id="invite-role"
                                class="woswidgets-select"
                                wire:model="selectedRole"
                            >
                                <option value="">No role</option>
                                @foreach($roles as $role)
                                    <option value="{{ $role['slug'] }}">
                                        {{ $role['name'] }}
                                        @if(!empty($role['default']))
                                            (default)
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    @if($error)
                        <div class="woswidgets-error" role="alert">
                            <p>{{ $error }}</p>
                        </div>
                    @endif

                    <div class="woswidgets-dialog-actions">
                        <button
                            type="submit"
                            class="woswidgets-button woswidgets-save-button"
                            wire:loading.attr="disabled"
                            wire:target="sendInvite"
                        >
                            <span wire:loading.remove wire:target="sendInvite">Send invite</span>
                            <span wire:loading wire:target="sendInvite">Sending...</span>
                        </button>
                        <button
                            type="button"
                            wire:click="closeModal"
                            class="woswidgets-button woswidgets-cancel-button"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
