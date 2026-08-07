<?php

declare(strict_types=1);

namespace Authkit\Authkit\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Throwable;
use WorkOS\SessionManager;

class InspectTokenCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'authkit:inspect-token
        {token? : The AuthKit token or sealed session string to inspect}
        {--cookie-password= : Override config(authkit.cookie_password) when unsealing}';

    /**
     * The command description.
     */
    protected $description = 'Decode a pasted AuthKit token (dev-only) to inspect iss/aud/claims for the token audit.';

    private const array CLAIM_KEYS = [
        'iss', 'aud', 'sub', 'client_id', 'org_id', 'role', 'roles',
        'permissions', 'entitlements', 'feature_flags', 'sid', 'jti', 'exp', 'iat',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->warn('Dev-only tool: decodes raw token claims. Do not paste production user tokens into shared terminals/CI logs.');

        $input = $this->argument('token');

        if ($input !== null) {
            $this->warn('Passing the token as an argument may leak it into shell history; prefer the interactive prompt.');
        } else {
            $input = $this->secret('Paste the AuthKit token or sealed session string');
        }

        if (! is_string($input) || trim($input) === '') {
            $this->error('No token provided.');

            return self::FAILURE;
        }

        try {
            $claims = $this->decodeJwtPayload($this->resolveAccessToken($input));
        } catch (Throwable $e) {
            $this->error("Could not decode token: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->printClaims($claims);

        $this->newLine();
        $this->line('Record the iss/aud values above into config/authkit.php (jwt.issuer / jwt.audience)');
        $this->line('and into docs/token-audit-findings.md before Phase 2 implementation begins.');

        return self::SUCCESS;
    }

    private function resolveAccessToken(string $input): string
    {
        if (substr_count($input, '.') === 2) {
            return $input; // already looks like a raw header.payload.signature JWT
        }

        $cookiePassword = $this->option('cookie-password') ?? config('authkit.cookie_password');

        if (! is_string($cookiePassword) || $cookiePassword === '') {
            throw new RuntimeException('No cookie password configured; pass --cookie-password or set authkit.cookie_password.');
        }

        try {
            $session = SessionManager::unsealData($input, $cookiePassword);
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Could not unseal the session (check --cookie-password / authkit.cookie_password): {$e->getMessage()}",
                previous: $e,
            );
        }

        if (! isset($session['access_token']) || ! is_string($session['access_token'])) {
            throw new RuntimeException('Unsealed session has no access_token.');
        }

        return $session['access_token'];
    }

    /** @return array<string, mixed> */
    private function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw new RuntimeException('Not a valid JWT (expected header.payload.signature).');
        }

        $claims = json_decode($this->base64UrlDecode($parts[1]), true);

        if (! is_array($claims)) {
            throw new RuntimeException('JWT payload is not valid JSON.');
        }

        return $claims;
    }

    private function base64UrlDecode(string $segment): string
    {
        $remainder = strlen($segment) % 4;

        if ($remainder !== 0) {
            $segment .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($segment, '-_', '+/'), true);

        if ($decoded === false) {
            throw new RuntimeException('Malformed base64url segment.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $claims */
    private function printClaims(array $claims): void
    {
        $this->newLine();

        foreach (self::CLAIM_KEYS as $key) {
            $this->components->twoColumnDetail($key, $this->displayClaim($key, $claims));
        }

        // The table above is padded to the terminal width, so long values get
        // clipped and any claim outside CLAIM_KEYS never appears at all. The
        // audit needs exact values, so follow it with the unabridged payload.
        $this->newLine();
        $this->line('Full decoded payload:');
        $this->line((string) json_encode($claims, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $claims */
    private function displayClaim(string $key, array $claims): string
    {
        // A claim present but explicitly null is a different finding from an
        // absent one, and telling them apart is exactly what the audit records.
        if (! array_key_exists($key, $claims)) {
            return '(not present)';
        }

        $value = $claims[$key];

        if ($value === null) {
            return '(null)';
        }

        if (is_array($value)) {
            return $this->displayArray($value);
        }

        if (! is_scalar($value)) {
            return (string) json_encode($value);
        }

        if (! is_bool($value) && in_array($key, ['exp', 'iat'], true)) {
            return sprintf('%s (%s)', $this->displayScalar($value), date(DATE_ATOM, (int) $value));
        }

        return $this->displayScalar($value);
    }

    /**
     * Claim members are not guaranteed to be strings — discovering their real
     * shape is the point of this audit — so anything that is not a plain list
     * keeps its keys as JSON rather than being flattened or, worse, triggering
     * an array-to-string conversion.
     *
     * @param  array<array-key, mixed>  $value
     */
    private function displayArray(array $value): string
    {
        if ($value === []) {
            return '(empty array)';
        }

        if (! array_is_list($value)) {
            return (string) json_encode($value);
        }

        return implode(', ', array_map(
            fn (mixed $item): string => is_scalar($item)
                ? $this->displayScalar($item)
                : (string) json_encode($item),
            $value,
        ));
    }

    private function displayScalar(bool|int|float|string $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
