<?php

declare(strict_types=1);

namespace Authkit\Authkit\Tests\Fixtures;

use WorkOS\SessionManager;

/**
 * Forges access tokens with fully controlled claims and a local JWKS.
 *
 * emulate signs server-side with its own key and exposes no "sign with this key
 * instead" knob, so the attacker-controlled-key, wrong-issuer, and tampered-bytes
 * cases are only reachable with a local keypair. Both keys are committed rather
 * than generated per run so SessionSecurity is deterministic across machines.
 */
final class JwtFixture
{
    public const string KEY_ID = 'test-key-1';

    /** Base64 of exactly 32 bytes — sodium_crypto_secretbox() rejects any other length. */
    public const string COOKIE_PASSWORD = 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

    public const string CLIENT_ID = 'client_fixture';

    public const string ISSUER = 'https://api.workos.com';

    public static function signingKeyPath(): string
    {
        return __DIR__.'/jwks/test-signing-key.pem';
    }

    /**
     * A second, valid key whose public half is never advertised in {@see jwks()} —
     * signing with it reproduces a real forged-token attempt rather than merely a
     * corrupted one.
     */
    public static function forgedKeyPath(): string
    {
        return __DIR__.'/jwks/test-forged-key.pem';
    }

    /**
     * @param  array<string, mixed>  $claimOverrides
     * @param  array<string, mixed>  $headerOverrides  e.g. a forged `alg` or an unknown `kid`
     */
    public static function sign(array $claimOverrides = [], ?string $signingKeyPath = null, array $headerOverrides = []): string
    {
        $privateKey = (string) file_get_contents($signingKeyPath ?? self::signingKeyPath());

        $header = array_merge(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => self::KEY_ID], $headerOverrides);
        $payload = array_merge([
            'sub' => 'user_fixture',
            'iss' => self::ISSUER,
            'client_id' => self::CLIENT_ID,
            'sid' => 'session_fixture',
            'jti' => 'jwt_fixture',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $claimOverrides);

        $segments = [
            self::b64((string) json_encode($header)),
            self::b64((string) json_encode($payload)),
        ];
        $signingInput = implode('.', $segments);

        $signature = '';
        openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $segments[] = self::b64($signature);

        return implode('.', $segments);
    }

    /**
     * @return array{keys: list<array{kid: string, kty: string, n: string, e: string}>}
     */
    public static function jwks(): array
    {
        $publicKey = openssl_pkey_get_public((string) file_get_contents(__DIR__.'/jwks/test-signing-key.pub.pem'));
        /** @var array{rsa: array{n: string, e: string}} $details */
        $details = openssl_pkey_get_details($publicKey);

        return ['keys' => [[
            'kid' => self::KEY_ID,
            'kty' => 'RSA',
            'n' => self::b64($details['rsa']['n']),
            'e' => self::b64($details['rsa']['e']),
        ]]];
    }

    /**
     * @param  array<string, mixed>|null  $user
     * @param  array<string, mixed>|null  $impersonator
     */
    public static function sealedCookie(
        string $accessToken,
        string $cookiePassword = self::COOKIE_PASSWORD,
        string $refreshToken = 'refresh_fixture',
        ?array $user = null,
        ?array $impersonator = null,
    ): string {
        // SessionManager::refresh() rejects any sealed session without a `user`
        // payload, so the default is a real array rather than null.
        return SessionManager::sealSessionFromAuthResponse(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            cookiePassword: $cookiePassword,
            user: $user ?? self::user(),
            impersonator: $impersonator,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function user(string $id = 'user_fixture', string $email = 'alice@acme.com'): array
    {
        return [
            'id' => $id,
            'email' => $email,
            'email_verified' => true,
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ];
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
