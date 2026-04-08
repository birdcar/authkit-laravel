<div class="{{ $this->themeClass() }} woswidgets-session-management" style="{{ $this->themeStyles() }}">
    <div class="woswidgets-card">
        <div class="woswidgets-card-header">
            <h3 class="woswidgets-section-title">Active sessions</h3>
            @if(!empty($sessions) && count($sessions) > 1)
                <button
                    wire:click="revokeAllSessions"
                    class="woswidgets-button woswidgets-destructive-button"
                    wire:loading.attr="disabled"
                    wire:target="revokeAllSessions"
                >
                    Revoke all
                </button>
            @endif
        </div>

        @if($loading)
            <div class="woswidgets-skeleton">
                @foreach(range(1, 3) as $i)
                    <div class="woswidgets-skeleton-line" style="width: {{ [75, 60, 80][$i - 1] }}%"></div>
                @endforeach
            </div>
        @elseif($error)
            <div class="woswidgets-error">
                <p>{{ $error }}</p>
                <button wire:click="loadSessions" class="woswidgets-button woswidgets-retry-button">Retry</button>
            </div>
        @elseif(empty($sessions))
            <div class="woswidgets-empty-state">
                <p>No active sessions found.</p>
            </div>
        @else
            <ul class="woswidgets-card-list">
                @foreach($sessions as $session)
                    @php
                        $isCurrent = $currentSessionId !== null && ($session['id'] ?? null) === $currentSessionId;
                    @endphp
                    <li class="woswidgets-card-list-item {{ $isCurrent ? 'woswidgets-card-list-item--current' : '' }}">
                        <div class="woswidgets-card-list-item-content">
                            <div class="woswidgets-session-info">
                                <span class="woswidgets-card-list-item-label">
                                    {{ $session['userAgent'] ?? $session['clientType'] ?? 'Session' }}
                                    @if($isCurrent)
                                        <span class="woswidgets-marker">Current</span>
                                    @endif
                                </span>
                                @if(!empty($session['ipAddress']))
                                    <span class="woswidgets-card-list-item-meta">{{ $session['ipAddress'] }}</span>
                                @endif
                                @if(!empty($session['lastActiveAt']))
                                    <span class="woswidgets-card-list-item-meta">
                                        Last active {{ \Carbon\Carbon::parse($session['lastActiveAt'])->diffForHumans() }}
                                    </span>
                                @endif
                            </div>
                        </div>
                        @if(!$isCurrent)
                            <button
                                wire:click="revokeSession('{{ $session['id'] }}')"
                                class="woswidgets-button woswidgets-destructive-button"
                                wire:loading.attr="disabled"
                                wire:target="revokeSession('{{ $session['id'] }}')"
                                aria-label="Revoke this session"
                            >
                                Revoke
                            </button>
                        @endif
                    </li>
                @endforeach
            </ul>

            @error('widget') <p class="woswidgets-error-message">{{ $message }}</p> @enderror
        @endif
    </div>
</div>
