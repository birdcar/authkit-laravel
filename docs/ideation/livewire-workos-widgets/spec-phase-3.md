# Spec: User Profile Widget

**Template**: ./spec-template-widget.md
**Estimated Effort**: XL

## Inputs

- Widget Group: `UserProfile`
- Scope: _(no permission scope required)_
- Endpoints: 15 (6 standard, 9 elevated)
- Elevated access: Yes — password creation, TOTP, passkeys require `POST /UserProfile/verify`

## Sub-Components

| Component | Class | Blade Tag | Purpose |
|-----------|-------|-----------|---------|
| ProfileInfo | `Livewire\Widgets\UserProfile\ProfileInfo` | `<livewire:workos-profile-info />` | Display + edit name, email, avatar. |
| SecuritySettings | `Livewire\Widgets\UserProfile\SecuritySettings` | `<livewire:workos-security-settings />` | Password management, TOTP setup/removal. Uses elevated access. |
| PasskeyManagement | `Livewire\Widgets\UserProfile\PasskeyManagement` | `<livewire:workos-passkey-management />` | Passkey registration, verification, removal. Uses elevated access + WebAuthn browser API. |
| SessionManagement | `Livewire\Widgets\UserProfile\SessionManagement` | `<livewire:workos-session-management />` | Active sessions list, revoke individual or all. |
| AuthenticationInfo | `Livewire\Widgets\UserProfile\AuthenticationInfo` | `<livewire:workos-authentication-info />` | Connected accounts, verification status. |
| **UserProfile** | `Livewire\Widgets\UserProfile\UserProfile` | `<livewire:workos-user-profile />` | Composed parent: tabbed layout with all sub-components. |

## Endpoints Used

| Method | Path | Elevated | Used By |
|--------|------|----------|---------|
| `GET` | `/UserProfile/me` | No | ProfileInfo (load profile) |
| `POST` | `/UserProfile/me` | No | ProfileInfo (update name) |
| `GET` | `/UserProfile/authentication-information` | No | AuthenticationInfo |
| `POST` | `/UserProfile/send-verification` | No | AuthenticationInfo (resend email verification) |
| `POST` | `/UserProfile/verify` | No | SecuritySettings, PasskeyManagement (get elevated token) |
| `POST` | `/UserProfile/update-password` | No | SecuritySettings (change existing password) |
| `POST` | `/UserProfile/create-password` | **Yes** | SecuritySettings (set initial password) |
| `POST` | `/UserProfile/create-totp-factor` | **Yes** | SecuritySettings (enable TOTP) |
| `POST` | `/UserProfile/verify-totp-factor` | **Yes** | SecuritySettings (verify TOTP setup) |
| `DELETE` | `/UserProfile/totp-factors` | **Yes** | SecuritySettings (disable TOTP) |
| `POST` | `/UserProfile/passkeys` | **Yes** | PasskeyManagement (start passkey registration) |
| `POST` | `/UserProfile/passkeys/verify` | **Yes** | PasskeyManagement (complete passkey registration) |
| `DELETE` | `/UserProfile/passkeys/{passkeyId}` | **Yes** | PasskeyManagement (remove passkey) |
| `GET` | `/UserProfile/sessions` | No | SessionManagement |
| `DELETE` | `/UserProfile/sessions/revoke/{sessionId}` | No | SessionManagement (revoke one) |
| `DELETE` | `/UserProfile/sessions/revoke-all` | No | SessionManagement (revoke all) |

## Deviations from Template

- **Elevated access flow**: SecuritySettings and PasskeyManagement use `WithElevatedAccess` trait. Before sensitive operations, prompt user for verification (password or TOTP code), call `POST /UserProfile/verify`, then use the elevated token for subsequent requests.
- **Passkey WebAuthn**: The passkey registration/verification flow requires browser-side WebAuthn API calls (`navigator.credentials.create()` / `navigator.credentials.get()`). This needs a small Alpine.js component to bridge browser → Livewire. The Livewire component sends `publicKeyCredentialCreationOptions` to the frontend, Alpine calls WebAuthn, returns the result back to Livewire via `$wire.call()`.
- **TOTP QR code**: `create-totp-factor` returns a `totp_uri` — render as QR code in Blade. Use a PHP QR code library or inline SVG generation.
- **Tabbed composed parent**: UserProfile uses a tab layout, not a vertical stack. Tabs: Profile, Security, Sessions.

## Events Dispatched

| Event | Trigger | Payload |
|-------|---------|---------|
| `profile-updated` | Name changed | `['firstName' => string, 'lastName' => string]` |
| `password-changed` | Password updated/created | `[]` |
| `totp-enabled` | TOTP factor verified | `[]` |
| `totp-disabled` | TOTP factor removed | `[]` |
| `passkey-registered` | Passkey added | `['passkeyId' => string]` |
| `passkey-removed` | Passkey deleted | `['passkeyId' => string]` |
| `session-revoked` | Session revoked | `['sessionId' => string]` |
| `all-sessions-revoked` | All sessions revoked | `[]` |

## Phase-Specific Concerns

- Elevated token flow is time-sensitive (10 min) — show timer or re-prompt if expired
- WebAuthn browser API is async and may fail (user cancels, hardware key not connected) — handle gracefully
- TOTP QR code should not be stored or logged — render once, clear from memory
- Session list should highlight the current session (match by session ID from WorkOS session)
- Password update vs create: different endpoints depending on whether user already has a password (check via `authentication-information`)

---

_Follow `spec-template-widget.md` with the inputs above._
