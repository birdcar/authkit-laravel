# Spec: User Management Widget

**Template**: ./spec-template-widget.md
**Estimated Effort**: L

## Inputs

- Widget Group: `UserManagement`
- Scope: `widgets:users-table:manage`
- Endpoints: 9 (`members`, `roles`, `roles-and-config`, `organizations`, `invite-user`, `invites/{userId}`, `invites/{userId}/resend`)
- Elevated access: No

## Sub-Components

| Component | Class | Blade Tag | Purpose |
|-----------|-------|-----------|---------|
| MembersTable | `Livewire\Widgets\UserManagement\MembersTable` | `<livewire:workos-members-table />` | Paginated table with avatar, name, email, role, last active, status. Server-side search + role filter. |
| MemberActions | `Livewire\Widgets\UserManagement\MemberActions` | `<livewire:workos-member-actions />` | Per-user dropdown: edit role, remove member. |
| InviteUser | `Livewire\Widgets\UserManagement\InviteUser` | `<livewire:workos-invite-user />` | Modal form: email, first/last name, role selection. |
| **UserManagement** | `Livewire\Widgets\UserManagement\UserManagement` | `<livewire:workos-user-management />` | Composed parent with table + invite button. |

## Endpoints Used

| Method | Path | Used By |
|--------|------|---------|
| `GET` | `/UserManagement/members` | MembersTable (paginated, searchable, filterable) |
| `POST` | `/UserManagement/members/{userId}` | MemberActions (update role) |
| `DELETE` | `/UserManagement/members/{userId}` | MemberActions (remove member) |
| `GET` | `/UserManagement/roles` | InviteUser, MemberActions (role dropdown options) |
| `GET` | `/UserManagement/roles-and-config` | MembersTable (role filter options) |
| `POST` | `/UserManagement/invite-user` | InviteUser (send invitation) |
| `POST` | `/UserManagement/invites/{userId}/resend` | MembersTable (resend invite action) |
| `DELETE` | `/UserManagement/invites/{userId}` | MembersTable (revoke invite action) |

## Deviations from Template

- MembersTable needs **server-side pagination** — add `$page`, `$perPage`, `$search`, `$roleFilter` properties with `updatingSearch` debounce
- MembersTable includes **avatar rendering** — use WorkOS user profile URLs or initials fallback
- InviteUser uses a **modal dialog** — wire:model for open/close state
- Role editing uses inline dropdown, not a separate page

## Events Dispatched

| Event | Trigger | Payload |
|-------|---------|---------|
| `member-role-updated` | Role change saved | `['userId' => string, 'role' => string]` |
| `member-removed` | Member deleted | `['userId' => string]` |
| `invite-sent` | Invitation sent | `['email' => string]` |
| `invite-revoked` | Invitation cancelled | `['userId' => string]` |
| `invite-resent` | Invitation resent | `['userId' => string]` |

## Phase-Specific Concerns

- Pagination query params: `?page=1&limit=10&search=term&role=admin` — verify against OpenAPI spec
- The `roles-and-config` endpoint returns both available roles AND org configuration — use for role filter dropdown
- Avatar URLs may be null — need initials fallback (first letter of first + last name)
- Invite flow requires email validation before API call
- Member removal should show a confirmation dialog before `DELETE`

---

_Follow `spec-template-widget.md` with the inputs above._
