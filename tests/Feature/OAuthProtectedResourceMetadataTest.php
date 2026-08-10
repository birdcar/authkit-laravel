<?php

declare(strict_types=1);

// Test path: no wire at all — the well-known document is pure config-to-JSON
// rendering (RFC 9728), so neither emulate nor MockHandler is involved.

uses()->group('connect-mcp');

it('serves exactly the three documented fields when configured without scopes', function (): void {
    config()->set('authkit.authkit_domain', 'fixture.authkit.app');
    config()->set('authkit.mcp.resource_indicator', 'https://mcp.fixture.test');

    $response = $this->getJson('/.well-known/oauth-protected-resource');

    // assertExactJson pins the full document: scopes_supported must be
    // absent entirely — not null — when authkit.mcp.scopes is unset
    // (spec-phase-10 finding F2 matches WorkOS's documented example).
    $response->assertOk()->assertExactJson([
        'resource' => 'https://mcp.fixture.test',
        'authorization_servers' => ['https://fixture.authkit.app'],
        'bearer_methods_supported' => ['header'],
    ]);
});

it('advertises scopes_supported only when scopes are configured', function (): void {
    config()->set('authkit.authkit_domain', 'fixture.authkit.app');
    config()->set('authkit.mcp.resource_indicator', 'https://mcp.fixture.test');
    config()->set('authkit.mcp.scopes', ['tools:read', 'tools:write']);

    $this->getJson('/.well-known/oauth-protected-resource')
        ->assertOk()
        ->assertExactJson([
            'resource' => 'https://mcp.fixture.test',
            'authorization_servers' => ['https://fixture.authkit.app'],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => ['tools:read', 'tools:write'],
        ]);
});

it('soft-404s when the resource indicator is not configured', function (): void {
    config()->set('authkit.authkit_domain', 'fixture.authkit.app');
    config()->set('authkit.mcp.resource_indicator', null);

    // 404, not 500: a default install that never touches MCP must not expose
    // a broken well-known endpoint (failure mode F10 — the deliberate
    // opposite of the middleware's fail-fast exception).
    $this->getJson('/.well-known/oauth-protected-resource')->assertNotFound();
});

it('soft-404s when the authkit domain is not configured', function (): void {
    config()->set('authkit.authkit_domain', null);
    config()->set('authkit.mcp.resource_indicator', 'https://mcp.fixture.test');

    $this->getJson('/.well-known/oauth-protected-resource')->assertNotFound();
});
