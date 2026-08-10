<?php

use Laravel\Mcp\Facades\Mcp;
use Workbench\App\Mcp\Servers\DemoServer;

// The laravel/mcp integration recipe (spec-phase-10 §6.5): secure a real MCP
// server with the authkit.mcp bearer middleware exactly like a normal route.
// An app copies these two lines into its own routes/ai.php.
Mcp::web('/mcp/demo', DemoServer::class)->middleware(['authkit.mcp']);
