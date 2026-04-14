<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Tests\Helpers;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use WorkOS\WorkOS;

class WorkOSSdkMock
{
    private MockHandler $mockHandler;

    private HandlerStack $handlerStack;

    /** @var array<int, array{request: RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    public function __construct()
    {
        $this->mockHandler = new MockHandler;
        $this->handlerStack = HandlerStack::create($this->mockHandler);

        $middleware = Middleware::history($this->history);
        $this->handlerStack->push($middleware);
    }

    public function getHandlerStack(): HandlerStack
    {
        return $this->handlerStack;
    }

    /**
     * Queue a JSON response.
     *
     * @param  array<string, mixed>  $body
     */
    public function queueJson(array $body, int $status = 200): static
    {
        $this->mockHandler->append(
            new Response($status, ['Content-Type' => 'application/json'], json_encode($body))
        );

        return $this;
    }

    /**
     * Queue an authorization URL response (for getAuthorizationUrl).
     * The SDK makes a GET to /user_management/authorize, and the response
     * is the URL string built from the request query params.
     */
    public function queueAuthorizationUrl(): static
    {
        // Queue a handler that captures the request and returns the full URL as a string
        $this->mockHandler->append(function (RequestInterface $request, array $options) {
            $url = (string) $request->getUri();

            return new Response(200, ['Content-Type' => 'application/json'], json_encode($url));
        });

        return $this;
    }

    /**
     * Queue a generic empty success response.
     */
    public function queueSuccess(): static
    {
        return $this->queueJson(['success' => true]);
    }

    /**
     * Queue an error response.
     */
    public function queueError(int $status = 500, string $message = 'Internal Server Error'): static
    {
        $this->mockHandler->append(
            new Response($status, ['Content-Type' => 'application/json'], json_encode(['message' => $message]))
        );

        return $this;
    }

    /**
     * Get the recorded request history.
     *
     * @return array<int, array{request: RequestInterface, options: array<string, mixed>}>
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    /**
     * Get the last recorded request.
     */
    public function getLastRequest(): ?RequestInterface
    {
        $last = end($this->history);

        return $last !== false ? $last['request'] : null;
    }

    /**
     * Create a \WorkOS\WorkOS client with this mock handler.
     */
    public function createClient(string $apiKey = 'sk_test_key', string $clientId = 'test_client_id'): WorkOS
    {
        return new WorkOS(
            apiKey: $apiKey,
            clientId: $clientId,
            handler: $this->getHandlerStack(),
        );
    }
}
