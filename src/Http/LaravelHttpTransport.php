<?php

declare(strict_types=1);

namespace Authkit\Authkit\Http;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Psr\Http\Message\RequestInterface;

/**
 * The Guzzle handler at the base of the WorkOS SDK's stack when
 * authkit.http.transport is 'laravel' (the default): every SDK request is
 * carried by the application's own HTTP client instead of a bare Guzzle
 * client the framework cannot see.
 *
 * This is what makes Laravel's native testing idioms work against WorkOS
 * traffic — Http::fake(), Http::preventStrayRequests(), Http::assertSent()
 * — and in production it means global HTTP middleware and the client's
 * request/response events observe WorkOS calls like any other outbound
 * request.
 *
 * The SDK's own semantics ride on top unchanged: its HttpClient sets
 * http_errors=false and maps status codes to typed exceptions itself, so
 * this handler only moves bytes. Its stack middleware (JWKS grace cache,
 * body preparation) wraps this handler exactly as it wraps Guzzle's curl
 * handler.
 */
final class LaravelHttpTransport
{
    /**
     * @param  array<string, mixed>  $options  Guzzle request options
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        try {
            $response = $this->pendingRequest($request, $options)->send(
                $request->getMethod(),
                (string) $request->getUri(),
                ($body = (string) $request->getBody()) === '' ? [] : ['body' => $body],
            );
        } catch (ConnectionException $exception) {
            // Guzzle's exception type is what the SDK's transport-retry loop
            // catches — a Laravel exception would skip the retry/backoff path.
            return Create::rejectionFor(new ConnectException($exception->getMessage(), $request, $exception));
        }

        return Create::promiseFor($response->toPsrResponse());
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function pendingRequest(RequestInterface $request, array $options): PendingRequest
    {
        // Resolved per call, never memoized: Http::fake() registers its stubs
        // on the factory the container serves NOW, and a captured instance
        // would go blind to a fake installed after this transport was built.
        $pending = app(Factory::class)
            ->withHeaders($request->getHeaders());

        $timeout = $options['timeout'] ?? null;

        if (is_numeric($timeout) && (float) $timeout > 0) {
            $pending = $pending->timeout((int) $timeout);
        }

        $connectTimeout = $options['connect_timeout'] ?? null;

        if (is_numeric($connectTimeout) && (float) $connectTimeout > 0) {
            $pending = $pending->connectTimeout((int) $connectTimeout);
        }

        return $pending;
    }
}
