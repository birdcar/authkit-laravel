<?php

declare(strict_types=1);

namespace Authkit\Authkit\Tests\Concerns;

use Authkit\Authkit\Contracts\WorkosClientManager as WorkosClientManagerContract;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Throwable;

trait UsesWorkosMockHandler
{
    protected MockHandler $workosMockHandler;

    /** @var array<int, array{request: RequestInterface}> */
    protected array $workosRequestHistory = [];

    /** @param array<int, Response|Throwable> $responses */
    protected function fakeWorkosResponses(array $responses): MockHandler
    {
        $this->workosMockHandler = new MockHandler($responses);

        // Middleware::history() keeps a reference to whatever it is handed, so a
        // fresh local array per call is what keeps successive stacks independent
        // — reassigning the property would write through to the previous stack.
        $history = [];
        $stack = HandlerStack::create($this->workosMockHandler);
        $stack->push(Middleware::history($history));
        $this->workosRequestHistory = &$history;

        $this->app->instance(HandlerStack::class, $stack);
        $this->app->forgetInstance(WorkosClientManagerContract::class);

        return $this->workosMockHandler;
    }
}
