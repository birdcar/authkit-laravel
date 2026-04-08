<div class="{{ $this->themeClass() }} woswidgets-user-management" style="{{ $this->themeStyles() }}">
    <div class="woswidgets-card">
        {{-- Toolbar --}}
        <div class="woswidgets-toolbar">
            <input
                type="search"
                class="woswidgets-text-field"
                placeholder="Search members..."
                wire:model.live.debounce.500ms="search"
                aria-label="Search members"
            />
            @if(count($roles) > 0)
                <select
                    class="woswidgets-select"
                    wire:model.live="roleFilter"
                    aria-label="Filter by role"
                >
                    <option value="">All roles</option>
                    @foreach($roles as $role)
                        <option value="{{ $role['slug'] }}">{{ $role['name'] }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        @if($loading)
            <div class="woswidgets-skeleton">
                @foreach(range(1, 5) as $i)
                    <div class="woswidgets-skeleton-line" style="width: {{ [80, 65, 75, 90, 70][($i - 1) % 5] }}%"></div>
                @endforeach
            </div>
        @elseif($error)
            <div class="woswidgets-error">
                <p>{{ $error }}</p>
                <button wire:click="loadMembers" class="woswidgets-button woswidgets-retry-button">Retry</button>
            </div>
        @elseif(empty($members))
            <div class="woswidgets-empty-state">
                <p>No members found.</p>
            </div>
        @else
            <table class="woswidgets-table" aria-label="Members">
                <thead>
                    <tr>
                        <th scope="col">Member</th>
                        <th scope="col">Role</th>
                        <th scope="col">Last active</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($members as $member)
                        @php
                            $initials = mb_strtoupper(
                                mb_substr($member['firstName'] ?? $member['email'] ?? '', 0, 1)
                                . mb_substr($member['lastName'] ?? '', 0, 1)
                            );
                            $statusMap = [
                                'Active' => 'active',
                                'Invited' => 'pending',
                                'InviteExpired' => 'inactive',
                                'InviteRevoked' => 'inactive',
                                'NoInvite' => 'inactive',
                            ];
                            $statusClass = $statusMap[$member['status'] ?? ''] ?? 'inactive';
                            $memberActions = $member['actions'] ?? [];
                        @endphp
                        <tr class="woswidgets-table-row">
                            <td>
                                <div class="woswidgets-member-cell">
                                    <span class="woswidgets-avatar">
                                        @if(!empty($member['profilePictureUrl']))
                                            <img src="{{ $member['profilePictureUrl'] }}" alt="{{ $member['firstName'] ?? '' }} {{ $member['lastName'] ?? '' }}" />
                                        @else
                                            {{ $initials }}
                                        @endif
                                    </span>
                                    <div class="woswidgets-member-info">
                                        <span class="woswidgets-member-name">
                                            {{ trim(($member['firstName'] ?? '') . ' ' . ($member['lastName'] ?? '')) ?: $member['email'] }}
                                            @if(!empty($member['isLoggedInUser']))
                                                <span class="woswidgets-marker">You</span>
                                            @endif
                                        </span>
                                        <span class="woswidgets-member-email">{{ $member['email'] }}</span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                @if(!empty($member['roles']))
                                    @foreach($member['roles'] as $role)
                                        <span class="woswidgets-marker">{{ $role['name'] ?? $role['slug'] ?? $role }}</span>
                                    @endforeach
                                @else
                                    <span class="woswidgets-no-role">—</span>
                                @endif
                            </td>
                            <td>
                                @if(!empty($member['lastActivityAt']))
                                    <time datetime="{{ $member['lastActivityAt'] }}">
                                        {{ \Carbon\Carbon::parse($member['lastActivityAt'])->diffForHumans() }}
                                    </time>
                                @else
                                    <span>—</span>
                                @endif
                            </td>
                            <td>
                                <span class="woswidgets-status woswidgets-status--{{ $statusClass }}">
                                    {{ $member['status'] ?? 'Unknown' }}
                                </span>
                            </td>
                            <td class="woswidgets-actions-cell">
                                @if(in_array('edit-role', $memberActions))
                                    <livewire:workos-member-actions
                                        :userId="$member['id']"
                                        :currentRole="$member['roles'][0]['slug'] ?? ($member['roles'][0] ?? '')"
                                        :accentColor="$accentColor"
                                        :borderColor="$borderColor"
                                        :backgroundColor="$backgroundColor"
                                        :foregroundColor="$foregroundColor"
                                        :appearance="$appearance"
                                        :key="'actions-'.$member['id']"
                                    />
                                @else
                                    @if(in_array('resend-invite', $memberActions))
                                        <button
                                            wire:click="resendInvite('{{ $member['id'] }}')"
                                            class="woswidgets-button woswidgets-cancel-button"
                                            wire:loading.attr="disabled"
                                            wire:target="resendInvite('{{ $member['id'] }}')"
                                        >
                                            Resend invite
                                        </button>
                                    @endif
                                    @if(in_array('revoke-invite', $memberActions))
                                        <button
                                            wire:click="revokeInvite('{{ $member['id'] }}')"
                                            class="woswidgets-button woswidgets-destructive-button"
                                            wire:loading.attr="disabled"
                                            wire:target="revokeInvite('{{ $member['id'] }}')"
                                        >
                                            Revoke invite
                                        </button>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            {{-- Pagination --}}
            @if($hasPrevious || $hasNext)
                <div class="woswidgets-pagination">
                    <button
                        wire:click="previousPage"
                        class="woswidgets-pagination-button"
                        @disabled(!$hasPrevious)
                        aria-label="Previous page"
                    >
                        &larr; Previous
                    </button>
                    <button
                        wire:click="nextPage"
                        class="woswidgets-pagination-button"
                        @disabled(!$hasNext)
                        aria-label="Next page"
                    >
                        Next &rarr;
                    </button>
                </div>
            @endif
        @endif
    </div>
</div>
