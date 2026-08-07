<?php

declare(strict_types=1);

namespace Authkit\Authkit\Http;

use Authkit\Authkit\Events\JwksServedStale;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Cache\Repository;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Guzzle middleware that keeps a JWKS outage from reading as "every session is
 * forged". Inside `SessionManager::authenticate()` a failed JWKS fetch and a
 * genuinely bad signature both collapse to `reason: invalid_jwt`, so the only
 * place the two are still distinguishable is here, at the transport layer.
 */
final readonly class JwksGraceCache
{
    public function __construct(
        private Repository $cache,
        private int $graceTtlSeconds,
    ) {}

    public function middleware(): callable
    {
        return fn (callable $handler): callable => function (RequestInterface $request, array $options) use ($handler) {
            // Scoped to JWKS only: a bug in here must not be able to affect org
            // lookups, user CRUD, or any other traffic on the shared client.
            if (! str_contains($request->getUri()->getPath(), '/sso/jwks/')) {
                return $handler($request, $options);
            }

            $cacheKey = 'authkit:jwks-grace:'.hash('sha256', (string) $request->getUri());

            return $handler($request, $options)->then(
                function (ResponseInterface $response) use ($cacheKey): ResponseInterface {
                    if ($response->getStatusCode() === 200) {
                        $body = (string) $response->getBody();
                        // Casting the body to string drains the stream to EOF. The
                        // SDK's HttpClient::decodeResponse() reads from the cursor,
                        // not from byte 0, so without this rewind it would see ''.
                        $response->getBody()->rewind();
                        $this->cache->put($cacheKey, $body, $this->graceTtlSeconds);

                        return $response;
                    }

                    // The SDK sets 'http_errors' => false, so a 5xx fulfils the
                    // promise rather than rejecting it and would never reach the
                    // rejection branch below.
                    if ($response->getStatusCode() >= 500) {
                        $stale = $this->cache->get($cacheKey);

                        if (is_string($stale)) {
                            event(new JwksServedStale('JWKS endpoint returned HTTP '.$response->getStatusCode()));

                            return self::staleResponse($stale);
                        }
                    }

                    return $response;
                },
                function (Throwable $reason) use ($cacheKey) {
                    $stale = $this->cache->get($cacheKey);

                    if (! is_string($stale)) {
                        return Create::rejectionFor($reason);
                    }

                    event(new JwksServedStale($reason->getMessage()));

                    return self::staleResponse($stale);
                },
            );
        };
    }

    private static function staleResponse(string $body): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], $body);
    }
}
