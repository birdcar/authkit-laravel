# AuthKit Token Audit Findings

**Status: NOT YET RUN — every value below is a placeholder.**

This file ships from Phase 1 in a `TBD` state. That is expected and correct at the end of
Phase 1. Follow [`token-audit.md`](./token-audit.md) against a real WorkOS sandbox environment
and replace every `TBD` below with an observed value.

**Phase 2 must not implement guard-level `iss`/`aud` enforcement while this file still reads
`TBD`.** There is nothing authoritative to enforce against until it does not.

## Audit run metadata

| Field | Value |
| --- | --- |
| Confirmed by | TBD |
| Date run | TBD |
| WorkOS environment | TBD |
| Client ID used | TBD |

## Canonical JWT claims

| Field | Observed value | Confirmed by | Date | WorkOS environment |
| --- | --- | --- | --- | --- |
| `iss` | TBD | TBD | TBD | TBD |
| `aud` | TBD | TBD | TBD | TBD |

## Default claim presence

Whether each claim appears in a default AuthKit access token with no extra dashboard
configuration. Record exactly what `authkit:inspect-token` reported, using one of three
values: `present` (a real value), `null` (the claim is in the token but null), or
`not present` (absent entirely). The middle case is not the same as absent — Phase 2's
guard has to handle it differently.

| Claim | Present by default? | Observed value | Confirmed by | Date | WorkOS environment |
| --- | --- | --- | --- | --- | --- |
| `role` | TBD | TBD | TBD | TBD | TBD |
| `roles` | TBD | TBD | TBD | TBD | TBD |
| `permissions` | TBD | TBD | TBD | TBD | TBD |
| `entitlements` | TBD | TBD | TBD | TBD | TBD |
| `feature_flags` | TBD | TBD | TBD | TBD | TBD |

## Notes

Record anything that surprised the operator — claims that required dashboard configuration
before appearing, values that differed between environments, or discrepancies against the
WorkOS documentation.

TBD
