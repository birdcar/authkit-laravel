<?php

declare(strict_types=1);

use Authkit\Authkit\Tests\Support\EmulateServer;

// Deliberately excluded from the fast inner-loop filter: this boots a real npx
// process. Run it with `vendor/bin/pest --filter=EmulateServer`.
beforeEach(function (): void {
    $this->server = new EmulateServer(port: 4199);
});

afterEach(function (): void {
    // Registered here rather than at the end of the test body so an assertion
    // failure mid-test cannot orphan the node process holding the port.
    if (isset($this->server)) {
        $this->server->stop();
    }
});

it('boots and reports healthy', function (): void {
    expect($this->server->isListening())->toBeFalse();

    $this->server->start();

    $health = file_get_contents($this->server->baseUrl().'/health');

    expect($health)->toBeString();
    expect(json_decode((string) $health, true))->toMatchArray(['status' => 'ok']);
})->skip(fn (): bool => ! EmulateServer::isAvailable(), 'npx/node not available');

it('releases the port on stop so the same port can be booted again', function (): void {
    $this->server->start();

    expect($this->server->isListening())->toBeTrue();

    $this->server->stop();

    // The real assertion: npx leaves a node grandchild holding the port unless
    // stop() reaps the whole chain, which would make the re-start below vacuous.
    expect($this->server->isListening())->toBeFalse();

    $this->server->start();

    expect(file_get_contents($this->server->baseUrl().'/health'))->toBeString();
})->skip(fn (): bool => ! EmulateServer::isAvailable(), 'npx/node not available');

it('exposes a loopback base url for the configured port', function (): void {
    expect((new EmulateServer(port: 4321))->baseUrl())->toBe('http://127.0.0.1:4321');
});
