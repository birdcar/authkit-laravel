# AuthKit Token Audit Findings

**Status: RUN — every value below is an observed value from real WorkOS access tokens.**

Captured per [`token-audit.md`](./token-audit.md) against a real WorkOS staging sandbox,
with one methodology substitution: tokens were minted with the SDK's password grant
(`authenticateWithPassword`) and an org-scoped refresh (`authenticateWithRefreshToken`
with `organization_id` — the exact call `authkit.switch-org` makes), then decoded.
Same token mint as a browser login; no emulator anywhere near it.

Guard-level `iss`/`aud` enforcement now has something authoritative to enforce against.

## Audit run metadata

| Field | Value |
| --- | --- |
| Confirmed by | Claude Code agent, authorized and directed by Nick Cannariato |
| Date run | 2026-08-13 |
| WorkOS environment | Laravel Enterprise Ready / Staging (sandbox), `environment_01KZY7K4SG4P0JE1R2VWSGWES3` |
| Client ID used | `client_01KZY7K54JJV1W2AQKYCC8GJMT` |

## Canonical JWT claims

| Field | Observed value | Confirmed by | Date | WorkOS environment |
| --- | --- | --- | --- | --- |
| `iss` | `https://api.workos.com/user_management/client_01KZY7K54JJV1W2AQKYCC8GJMT` — i.e. `{base_url}/user_management/{client_id}` | agent (Nick) | 2026-08-13 | Laravel Enterprise Ready / Staging |
| `aud` | `client_01KZY7K54JJV1W2AQKYCC8GJMT` — the client ID, as a plain string (not an array) | agent (Nick) | 2026-08-13 | Laravel Enterprise Ready / Staging |

`authkit.jwt.issuer` guidance: for a default environment set it to
`https://api.workos.com/user_management/{WORKOS_CLIENT_ID}`. **Unverified caveat:** an
environment with a custom auth domain may mint a different issuer — re-run this audit
before enforcing `iss` on such an environment.

## Default claim presence

Observed across three tokens: fresh login auto-scoped to the user's single organization,
an explicit org-scoped refresh, and a fresh login for a user with **zero** memberships.
No dashboard JWT template was configured — this is stock token shape.

| Claim | Present by default? | Observed value | Confirmed by | Date | WorkOS environment |
| --- | --- | --- | --- | --- | --- |
| `role` | present (org-scoped session); absent entirely for a zero-membership user | `"admin"` (slug, singular string) | agent (Nick) | 2026-08-13 | Laravel Enterprise Ready / Staging |
| `roles` | present (org-scoped session); absent entirely for a zero-membership user | `["admin"]` (array of slugs — emitted even with `multipleRolesEnabled` off) | agent (Nick) | 2026-08-13 | Laravel Enterprise Ready / Staging |
| `permissions` | present (org-scoped session); absent entirely for a zero-membership user | `["widgets:dsync:manage", "widgets:users-table:manage"]` (the role's permission slugs) | agent (Nick) | 2026-08-13 | Laravel Enterprise Ready / Staging |
| `entitlements` | not present | — (absent in all three tokens; environment has no entitlements configured) | agent (Nick) | 2026-08-13 | Laravel Enterprise Ready / Staging |
| `feature_flags` | present — **even for a zero-membership user** | `["team-plan"]` (slugs of enabled flags targeting the user) | agent (Nick) | 2026-08-13 | Laravel Enterprise Ready / Staging |

## Notes

- **`provider=authkit` is required on `/authorize`.** A selector-less authorize URL
  redirects to `error.workos.com/sso/invalid-connection-selector` before any login page
  renders; with `provider=authkit` it lands on the environment's hosted AuthKit domain.
  The emulator accepts selector-less requests, which is how this package shipped the gap —
  fixed in `AuthKitLoginRequest` alongside this audit. The docs agree
  (get-authorization-url: set `provider` to `authkit`).
- **Auto-scoping:** a fresh login for a user with exactly one membership arrives already
  org-scoped — `org_id`, `role`, `roles`, `permissions` all present without requesting an
  organization. `AuthenticateResponse.organizationId` matches the claim.
- **Zero-membership tokens omit the org claims entirely** (`org_id`/`role`/`roles`/
  `permissions` are *absent*, not null). Phase 3's zero-org onboarding must branch on
  claim absence, and `feature_flags` still works there — user-targeted flags are
  org-independent.
- **RBAC needs no JWT template.** `role`/`roles`/`permissions` appear as soon as the
  membership has a role and the role has permissions. This environment shipped with
  default `member` (no permissions) and `admin` (widget permissions) roles.
- **Flags need Dashboard setup before the claim carries anything:** create the flag and
  enable it for the environment with targeting (`SOME` + user, or org, or `ALL`).
- Access token lifetime observed: 300 s (`exp - iat`), matching the AuthKit application's
  `accessTokenExpiry`.
- Other claims present: `sub` (user id), `sid` (session id), `jti`, `auth_time`,
  `client_id` (duplicate of `aud`), `exp`, `iat`.
- **Password-grant caveat for future audits:** a user whose email domain is
  verified + SSO-captured by an organization is refused the password grant ("User must
  authenticate using one of the matching connections"), and users created via the
  dashboard API start email-unverified (also refused). The audit fixture uses a neutral
  domain and an SDK `updateUser(emailVerified: true)`.
