<?php

namespace Workbench\App\Mcp\Servers;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Resource as McpResource;
use Laravel\Mcp\Server\Tool;

/**
 * The laravel/mcp integration recipe's demo server (spec-phase-10 §6.5): an
 * intentionally empty Server proving that `authkit.mcp` composes with
 * Mcp::web() routing — the middleware rejects unauthenticated JSON-RPC calls
 * before laravel/mcp ever sees them.
 */
class DemoServer extends Server
{
    /** @var array<int, Tool|class-string<Tool>> */
    protected array $tools = [];

    /** @var array<int, McpResource|class-string<McpResource>> */
    protected array $resources = [];

    /** @var array<int, Prompt|class-string<Prompt>> */
    protected array $prompts = [];
}
