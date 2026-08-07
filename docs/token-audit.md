# AuthKit Token Audit Procedure

## Why this exists

The vendored WorkOS PHP SDK does **not** verify a JWT's `iss` or `aud` claims.
`SessionManager::decodeAccessToken()` carries an explicit TODO deferring this until the
documented WorkOS values are "empirically confirmed", and no other package document records
them. Phase 2's `workos` guard cannot safely enforce `iss`/`aud` against values nobody has
verified, and the zero-HTTP RBAC, claim-first feature flags, and quickstart goals all
silently assume `role`/`permissions`/`feature_flags` are present in a default AuthKit token
without extra dashboard setup.

This procedure resolves both unknowns against a real WorkOS environment and records the
answer in [`token-audit-findings.md`](./token-audit-findings.md).

**Phase 2 must not begin guard-level `iss`/`aud` enforcement until steps 5 and 6 below are
done with real, non-placeholder values.**

## Prerequisites

- A real WorkOS dashboard test/sandbox environment.

  Do **not** use `workos/emulate` for this audit. The emulator mints its own signing key and
  issuer (its `--issuer` flag defaults to the emulator's own URL), so it cannot answer "what
  is the canonical production `iss`". Use it for wire-level tests, not for this question.
- `WORKOS_API_KEY` and `WORKOS_CLIENT_ID` for that environment.
- PHP with this package's dependencies installed (`composer install`).

## Procedure

### 1. Point the package at a real WorkOS environment

Set real `WORKOS_API_KEY` and `WORKOS_CLIENT_ID` values in `.env`. Leave
`AUTHKIT_EMULATE_ENABLED` unset or `false`.

### 2. Complete one real AuthKit login to obtain an access token

Save the following throwaway script as `audit-callback.php` at the project root. Add
`http://localhost:8080/audit-callback.php` as a redirect URI in the WorkOS dashboard, run
`php -S localhost:8080`, then visit that URL in a browser and complete the AuthKit login.
The script prints the access token to paste into step 3.

```php
<?php
// audit-callback.php — throwaway script, delete after use.
require __DIR__.'/vendor/autoload.php';

use WorkOS\PKCEHelper;
use WorkOS\Resource\UserManagementAuthenticationProvider;
use WorkOS\WorkOS;

$client = new WorkOS(apiKey: getenv('WORKOS_API_KEY'), clientId: getenv('WORKOS_CLIENT_ID'));
$redirectUri = 'http://localhost:8080/audit-callback.php';

if (! isset($_GET['code'])) {
    $pkce = PKCEHelper::generate();
    file_put_contents(__DIR__.'/.audit-verifier', $pkce['code_verifier']);

    $url = $client->userManagement()->getAuthorizationUrl(
        redirectUri: $redirectUri,
        codeChallengeMethod: $pkce['code_challenge_method'],
        codeChallenge: $pkce['code_challenge'],
        provider: UserManagementAuthenticationProvider::Authkit,
    );
    header('Location: '.$url);
    exit;
}

$verifier = file_get_contents(__DIR__.'/.audit-verifier');
$result = $client->pkce()->authKitCodeExchange($_GET['code'], $verifier);

echo $result['access_token']; // paste this into `php artisan authkit:inspect-token`
```

Two SDK details this script depends on, both confirmed by reading the vendored source:

- `UserManagement::getAuthorizationUrl()` builds the URL locally via `HttpClient::buildUrl()`
  and returns a plain `string`. It issues no HTTP request.
- It deliberately does **not** use `PKCEHelper::getAuthKitAuthorizationUrl()`. That method
  issues a real GET to `user_management/authorize` and assigns the *decoded response body* to
  `url`. Guzzle follows the redirect onto the AuthKit-hosted HTML login page,
  `HttpClient::decodeResponse()` then tries to `json_decode` that HTML, gets a non-array, and
  throws `ApiException` — before `header('Location: ...')` is ever reached.

`WorkOS::pkce()->authKitCodeExchange()` is a real POST by design and is used unchanged.

Delete `audit-callback.php` and `.audit-verifier` when you are done, and remove the temporary
redirect URI from the dashboard.

### 3. Decode the token

```bash
php artisan authkit:inspect-token
```

Paste the token at the interactive prompt. Prefer the prompt over passing the token as a CLI
argument — an argument persists in your shell history.

### 4. Record what was printed

Note the printed `iss` and `aud`, and what each of `role`, `roles`, `permissions`,
`entitlements`, and `feature_flags` printed. There are three distinct outcomes, and the
difference between the last two matters to Phase 2:

| Printed | Meaning |
| --- | --- |
| a real value | the claim is present and populated |
| `(null)` | the claim **is** in the token, but its value is null |
| `(not present)` | the claim is absent from the token entirely |

The command also prints a `Full decoded payload:` block below the table. Use that block,
not the table, when transcribing exact values — the table is padded to the terminal width
and clips long values, and it only lists well-known claims. The payload block shows
everything the token actually carries, including claims this package does not yet know about.

### 5. Update the config defaults

Replace the `null` placeholders in `config/authkit.php` with the confirmed literal values:

```php
'jwt' => [
    'issuer' => env('WORKOS_JWT_ISSUER', '<confirmed iss>'),
    'audience' => env('WORKOS_JWT_AUDIENCE', '<confirmed aud>'),
],
```

### 6. Append the findings

Fill in [`token-audit-findings.md`](./token-audit-findings.md), replacing every `TBD` with the
observed value and recording who ran the audit, when, and against which WorkOS environment.

### 7. Confirm the gate is cleared

Phase 2 may begin guard-level `iss`/`aud` enforcement only once steps 5 and 6 hold real
values. If `token-audit-findings.md` still reads `TBD`, the audit has not been done.

## Related risk

`AUTHKIT_EMULATE_ENABLED=true` is a development and test setting only. If it reaches a staging
or production `.env`, every WorkOS call silently redirects to `localhost:4100` and the failure
looks identical to a WorkOS outage. This package adds no runtime guard against that, because
detecting it would require the environment-name sniffing the package deliberately avoids.
