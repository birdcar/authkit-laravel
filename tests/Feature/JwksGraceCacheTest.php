<?php

declare(strict_types=1);

use Authkit\Authkit\Events\JwksServedStale;
use Authkit\Authkit\Http\JwksGraceCache;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Event;

function jwksClient(MockHandler $handler): Client
{
    $stack = HandlerStack::create($handler);
    $stack->push(app(JwksGraceCache::class)->middleware());

    return new Client(['handler' => $stack, 'http_errors' => false]);
}

function jwksRequest(): Request
{
    return new Request('GET', 'https://api.workos.com/sso/jwks/client_fixture');
}

it('serves the last good body when the JWKS fetch fails at the transport layer', function (): void {
    Event::fake([JwksServedStale::class]);

    $handler = new MockHandler([
        new Response(200, [], '{"keys":[{"kid":"live"}]}'),
        new ConnectException('Connection refused', jwksRequest()),
    ]);
    $client = jwksClient($handler);

    expect((string) $client->send(jwksRequest())->getBody())->toBe('{"keys":[{"kid":"live"}]}');
    expect((string) $client->send(jwksRequest())->getBody())->toBe('{"keys":[{"kid":"live"}]}');

    Event::assertDispatched(JwksServedStale::class);
});

it('serves the last good body on a 5xx, which the SDK turns into a fulfilled promise', function (): void {
    Event::fake([JwksServedStale::class]);

    // The SDK sets http_errors => false, so a 500 never rejects — without the
    // fulfilment-branch check the grace cache would never see this case.
    $handler = new MockHandler([
        new Response(200, [], '{"keys":[{"kid":"live"}]}'),
        new Response(503, [], 'upstream unavailable'),
    ]);
    $client = jwksClient($handler);

    $client->send(jwksRequest());
    $stale = $client->send(jwksRequest());

    expect($stale->getStatusCode())->toBe(200)
        ->and((string) $stale->getBody())->toBe('{"keys":[{"kid":"live"}]}');

    Event::assertDispatched(JwksServedStale::class);
});

it('propagates the failure when nothing was ever cached', function (): void {
    $client = jwksClient(new MockHandler([new ConnectException('Connection refused', jwksRequest())]));

    expect(fn () => $client->send(jwksRequest()))->toThrow(ConnectException::class);
});

it('leaves the response body readable for the caller after caching it', function (): void {
    // (string) $response->getBody() drains the stream; without the rewind the SDK
    // would read '' on the very next line.
    $client = jwksClient(new MockHandler([new Response(200, [], '{"keys":[]}')]));

    expect($client->send(jwksRequest())->getBody()->getContents())->toBe('{"keys":[]}');
});

it('ignores requests that are not JWKS fetches', function (): void {
    $client = jwksClient(new MockHandler([new ConnectException('Connection refused', jwksRequest())]));

    expect(fn () => $client->send(new Request('GET', 'https://api.workos.com/user_management/users')))
        ->toThrow(ConnectException::class);
});

it('is wired into the handler stack the WorkOS client is built with', function (): void {
    // The SDK keeps its Guzzle client private, so binding the stack in the service
    // provider is the only seam that gets this middleware in front of JWKS traffic.
    // Asserted behaviorally: merely checking the binding exists would still pass if
    // the provider stopped pushing the middleware onto it.
    $mock = new MockHandler([
        new Response(200, [], '{"keys":[{"kid":"from-the-provider-stack"}]}'),
        new ConnectException('Connection refused', jwksRequest()),
    ]);

    $stack = app(HandlerStack::class);
    $stack->setHandler($mock);
    $client = new Client(['handler' => $stack, 'http_errors' => false]);

    $client->send(jwksRequest());

    expect((string) $client->send(jwksRequest())->getBody())->toBe('{"keys":[{"kid":"from-the-provider-stack"}]}');
});
