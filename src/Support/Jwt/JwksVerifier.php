<?php

declare(strict_types=1);

namespace Authkit\Authkit\Support\Jwt;

use Authkit\Authkit\Support\Jwt\Exceptions\JwksUnavailableException;
use Authkit\Authkit\Support\Jwt\Exceptions\JwtVerificationException;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * URL-parameterized JWKS signature verification (spec-phase-10 §4.1).
 *
 * The session JWKS lives at `{base_url}/sso/jwks/{client_id}` (verified inside
 * the SDK's SessionManager), but the MCP resource-server JWKS lives at
 * `https://{authkit_domain}/oauth2/jwks` — a different host and path entirely,
 * which is why the JWKS URL and cache key are parameters here rather than
 * config reads. Verification mechanics mirror the SDK's own
 * `SessionManager::decodeAccessToken()` (RS256 allow-list before anything
 * else, required `kid`, one forced JWKS refresh on a `kid` miss, `exp` after
 * signature), swapping its in-memory cache for the Laravel cache so the fetch
 * survives across requests, with the forced refresh debounced so a stream of
 * bogus `kid` values cannot stampede the JWKS endpoint (failure mode F9).
 *
 * Deliberately does NOT check `iss`/`aud` — every caller has a different
 * audience/issuer policy, so that check stays with the caller.
 */
final class JwksVerifier
{
    /**
     * `none` and every HMAC/EC algorithm are rejected before any JWKS fetch or
     * signature math — mirrors SessionManager::ALLOWED_JWS_ALGORITHMS.
     */
    private const array ALLOWED_JWS_ALGORITHMS = ['RS256'];

    /**
     * Seconds during which a `kid` miss will NOT trigger another forced JWKS
     * refresh. Long enough to absorb a bogus-`kid` flood, short enough that a
     * genuine key rotation is picked up almost immediately.
     */
    private const int KID_REFRESH_DEBOUNCE_SECONDS = 10;

    private const int FETCH_TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly Repository $cache,
        private readonly ?HandlerStack $handler = null,
    ) {}

    /**
     * Fetch (Laravel-cache-backed, force-refresh once on an unrecognized
     * `kid`) the JWKS at $jwksUrl, verify $jwt's RS256 signature against it,
     * and check `exp`.
     *
     * @return array<string, mixed> decoded claims
     *
     * @throws JwtVerificationException when the token itself is invalid
     * @throws JwksUnavailableException when the JWKS cannot be fetched and no cached copy exists
     */
    public function verify(string $jwt, string $jwksUrl, string $cacheKey, int $ttlSeconds = 300): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw JwtVerificationException::malformedToken();
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = self::decodeJsonSegment($headerB64);

        if ($header === null) {
            throw JwtVerificationException::malformedToken();
        }

        $alg = $header['alg'] ?? null;

        if (! is_string($alg) || ! in_array($alg, self::ALLOWED_JWS_ALGORITHMS, true)) {
            throw JwtVerificationException::disallowedAlgorithm();
        }

        $kid = $header['kid'] ?? null;

        if (! is_string($kid) || $kid === '') {
            throw JwtVerificationException::missingKeyId();
        }

        $payload = self::decodeJsonSegment($payloadB64);

        if ($payload === null) {
            throw JwtVerificationException::malformedToken();
        }

        $signature = self::base64UrlDecode($signatureB64);

        if ($signature === false || $signature === '') {
            throw JwtVerificationException::malformedToken();
        }

        $jwk = $this->resolveJwk($jwksUrl, $cacheKey, $ttlSeconds, $kid);

        $verified = openssl_verify(
            $headerB64.'.'.$payloadB64,
            $signature,
            self::jwkToRsaPublicKeyPem($jwk),
            OPENSSL_ALGO_SHA256,
        );

        if ($verified !== 1) {
            throw JwtVerificationException::invalidSignature();
        }

        // Expiration check after signature verification, SDK parity: an
        // attacker who controls `exp` but not the key learns nothing extra.
        if (isset($payload['exp']) && is_numeric($payload['exp']) && (int) $payload['exp'] < time()) {
            throw JwtVerificationException::expired();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveJwk(string $jwksUrl, string $cacheKey, int $ttlSeconds, string $kid): array
    {
        $jwks = $this->cachedJwks($jwksUrl, $cacheKey, $ttlSeconds);
        $jwk = self::findJwkByKid($jwks, $kid);

        // Unknown kid: force-refresh once so newly-rotated keys are discovered
        // without waiting for TTL expiry — debounced via an atomic cache add()
        // so many distinct bogus kids collapse into one refetch per window.
        if ($jwk === null && $this->cache->add($cacheKey.':kid-refresh', true, self::KID_REFRESH_DEBOUNCE_SECONDS)) {
            $jwks = $this->fetchJwks($jwksUrl);
            $this->cache->put($cacheKey, $jwks, $ttlSeconds);
            $jwk = self::findJwkByKid($jwks, $kid);
        }

        if ($jwk === null) {
            throw JwtVerificationException::unknownSigningKey();
        }

        return $jwk;
    }

    /**
     * @return array<string, mixed>
     */
    private function cachedJwks(string $jwksUrl, string $cacheKey, int $ttlSeconds): array
    {
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            /** @var array<string, mixed> $cached */
            return $cached;
        }

        $jwks = $this->fetchJwks($jwksUrl);
        $this->cache->put($cacheKey, $jwks, $ttlSeconds);

        return $jwks;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJwks(string $jwksUrl): array
    {
        $client = new Client([
            'handler' => $this->handler ?? HandlerStack::create(),
            'http_errors' => false,
            'timeout' => self::FETCH_TIMEOUT_SECONDS,
        ]);

        try {
            $response = $client->get($jwksUrl);
        } catch (Throwable $e) {
            throw JwksUnavailableException::fetchFailed($jwksUrl, $e);
        }

        if ($response->getStatusCode() !== 200) {
            throw JwksUnavailableException::unexpectedStatus($jwksUrl, $response->getStatusCode());
        }

        $decoded = json_decode((string) $response->getBody(), true);

        if (! is_array($decoded)) {
            throw JwksUnavailableException::invalidDocument($jwksUrl);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $jwks
     * @return array<string, mixed>|null
     */
    private static function findJwkByKid(array $jwks, string $kid): ?array
    {
        $keys = $jwks['keys'] ?? null;

        if (! is_array($keys)) {
            return null;
        }

        foreach ($keys as $jwk) {
            if (is_array($jwk) && ($jwk['kid'] ?? null) === $kid) {
                /** @var array<string, mixed> $jwk */
                return $jwk;
            }
        }

        return null;
    }

    /**
     * Convert an RSA JWK (`kty=RSA`, base64url `n`/`e`) to a PEM-encoded
     * public key for openssl_verify(). Builds the DER SubjectPublicKeyInfo by
     * hand — same approach as the SDK's SessionManager — to avoid a hard
     * dependency on a JWT library.
     *
     * @param  array<string, mixed>  $jwk
     */
    private static function jwkToRsaPublicKeyPem(array $jwk): string
    {
        if (($jwk['kty'] ?? null) !== 'RSA') {
            throw JwtVerificationException::malformedKey();
        }

        $n = $jwk['n'] ?? null;
        $e = $jwk['e'] ?? null;

        if (! is_string($n) || ! is_string($e)) {
            throw JwtVerificationException::malformedKey();
        }

        $modulus = self::base64UrlDecode($n);
        $exponent = self::base64UrlDecode($e);

        if ($modulus === false || $exponent === false) {
            throw JwtVerificationException::malformedKey();
        }

        $modulusDer = self::derEncodeUnsignedInteger($modulus);
        $exponentDer = self::derEncodeUnsignedInteger($exponent);
        $rsaPublicKey = self::derEncodeSequence($modulusDer.$exponentDer);
        $bitString = self::derEncodeBitString($rsaPublicKey);

        // AlgorithmIdentifier: SEQUENCE { OID 1.2.840.113549.1.1.1, NULL }.
        $rsaOid = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";
        $algorithmIdentifier = self::derEncodeSequence($rsaOid."\x05\x00");
        $spki = self::derEncodeSequence($algorithmIdentifier.$bitString);

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($spki), 64, "\n")
            .'-----END PUBLIC KEY-----'."\n";
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeJsonSegment(string $segment): ?array
    {
        $json = self::base64UrlDecode($segment);

        if ($json === false) {
            return null;
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function base64UrlDecode(string $segment): string|false
    {
        $remainder = strlen($segment) % 4;

        if ($remainder !== 0) {
            $segment .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($segment, '-_', '+/'), true);
    }

    /**
     * @param  int<0, max>  $length
     */
    private static function derEncodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';

        while ($length > 0) {
            $bytes = chr($length & 0xFF).$bytes;
            $length >>= 8;
        }

        // DER long form: first byte is 0x80 | number-of-length-bytes. The
        // count is at most PHP_INT_SIZE (8), so the 7-bit mask never bites —
        // it encodes DER's own <= 127 length-of-length constraint.
        return chr(0x80 | (strlen($bytes) & 0x7F)).$bytes;
    }

    private static function derEncodeSequence(string $contents): string
    {
        return "\x30".self::derEncodeLength(strlen($contents)).$contents;
    }

    private static function derEncodeUnsignedInteger(string $bytes): string
    {
        // Strip leading zero bytes, then re-prepend a single 0x00 if the high
        // bit of the first byte is set so the value stays positive.
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '') {
            $bytes = "\x00";
        } elseif ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".self::derEncodeLength(strlen($bytes)).$bytes;
    }

    private static function derEncodeBitString(string $bytes): string
    {
        // 0x00 = number of unused bits in the final octet (always zero here).
        $contents = "\x00".$bytes;

        return "\x03".self::derEncodeLength(strlen($contents)).$contents;
    }
}
