<div class="{{ $this->themeClass() }} woswidgets-user-profile-info" style="{{ $this->themeStyles() }}">
    <div class="woswidgets-card">
        @if($loading)
            <div class="woswidgets-skeleton">
                <div class="woswidgets-skeleton-avatar"></div>
                <div class="woswidgets-skeleton-line" style="width: 60%"></div>
                <div class="woswidgets-skeleton-line" style="width: 40%"></div>
            </div>
        @elseif($error)
            <div class="woswidgets-error">
                <p>{{ $error }}</p>
                <button wire:click="loadProfile" class="woswidgets-button woswidgets-retry-button">Retry</button>
            </div>
        @elseif(empty($profile))
            <div class="woswidgets-empty-state">
                <p>Profile not available.</p>
            </div>
        @else
            <div class="woswidgets-profile-info-layout">
                <div class="woswidgets-avatar woswidgets-avatar--large">
                    @if(!empty($profile['profilePictureUrl']))
                        <img
                            src="{{ $profile['profilePictureUrl'] }}"
                            alt="{{ trim(($profile['firstName'] ?? '') . ' ' . ($profile['lastName'] ?? '')) }}"
                        />
                    @else
                        @php
                            $initials = mb_strtoupper(
                                mb_substr($profile['firstName'] ?? $profile['email'] ?? '', 0, 1)
                                . mb_substr($profile['lastName'] ?? '', 0, 1)
                            );
                        @endphp
                        {{ $initials }}
                    @endif
                </div>

                <div class="woswidgets-profile-info-body">
                    @if($editing)
                        <form wire:submit="updateProfile" class="woswidgets-profile-edit-form">
                            <div class="woswidgets-form-row">
                                <div class="woswidgets-form-group">
                                    <label for="firstName" class="woswidgets-label">First name</label>
                                    <input
                                        id="firstName"
                                        type="text"
                                        wire:model="firstName"
                                        class="woswidgets-text-field"
                                        placeholder="First name"
                                        autocomplete="given-name"
                                    />
                                    @error('firstName') <span class="woswidgets-field-error">{{ $message }}</span> @enderror
                                </div>
                                <div class="woswidgets-form-group">
                                    <label for="lastName" class="woswidgets-label">Last name</label>
                                    <input
                                        id="lastName"
                                        type="text"
                                        wire:model="lastName"
                                        class="woswidgets-text-field"
                                        placeholder="Last name"
                                        autocomplete="family-name"
                                    />
                                    @error('lastName') <span class="woswidgets-field-error">{{ $message }}</span> @enderror
                                </div>
                            </div>

                            @error('widget') <p class="woswidgets-error-message">{{ $message }}</p> @enderror

                            <div class="woswidgets-action-row">
                                <button type="submit" class="woswidgets-button woswidgets-save-button" wire:loading.attr="disabled">
                                    Save
                                </button>
                                <button type="button" wire:click="cancelEditing" class="woswidgets-button woswidgets-cancel-button">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    @else
                        <div class="woswidgets-profile-name-row">
                            <h3 class="woswidgets-section-title">
                                {{ trim(($profile['firstName'] ?? '') . ' ' . ($profile['lastName'] ?? '')) ?: 'No name set' }}
                            </h3>
                            <button wire:click="startEditing" class="woswidgets-button woswidgets-edit-button" aria-label="Edit name">
                                Edit
                            </button>
                        </div>
                        <p class="woswidgets-profile-email">{{ $profile['email'] ?? '' }}</p>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
