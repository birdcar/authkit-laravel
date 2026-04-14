<?php

declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use WorkOS\AuthKit\Services\DomainService;
use WorkOS\Exception\NotFoundException;
use WorkOS\WorkOS;

function makeDomainClient(MockHandler $mock): WorkOS
{
    $stack = HandlerStack::create($mock);

    return new WorkOS(apiKey: 'sk_test_domain', handler: $stack);
}

it('creates an organization domain', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'object' => 'organization_domain',
            'id' => 'dom_123',
            'organization_id' => 'org_456',
            'domain' => 'example.com',
            'created_at' => '2024-01-01T00:00:00.000Z',
            'updated_at' => '2024-01-01T00:00:00.000Z',
            'state' => 'pending',
            'verification_prefix' => '_workos',
            'verification_token' => 'tok_abc',
            'verification_strategy' => 'dns',
        ])),
    ]);

    $service = new DomainService(makeDomainClient($mock));
    $result = $service->create('org_456', 'example.com');

    expect($result['id'])->toBe('dom_123')
        ->and($result['state'])->toBe('pending')
        ->and($result['verification_token'])->toBe('tok_abc');
});

it('gets an organization domain', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'object' => 'organization_domain',
            'id' => 'dom_123',
            'organization_id' => 'org_456',
            'domain' => 'example.com',
            'created_at' => '2024-01-01T00:00:00.000Z',
            'updated_at' => '2024-01-01T00:00:00.000Z',
            'state' => 'verified',
        ])),
    ]);

    $service = new DomainService(makeDomainClient($mock));
    $result = $service->get('dom_123');

    expect($result['state'])->toBe('verified');
});

it('verifies an organization domain', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'object' => 'organization_domain',
            'id' => 'dom_123',
            'organization_id' => 'org_456',
            'domain' => 'example.com',
            'created_at' => '2024-01-01T00:00:00.000Z',
            'updated_at' => '2024-01-01T00:00:00.000Z',
            'state' => 'verified',
        ])),
    ]);

    $service = new DomainService(makeDomainClient($mock));
    $result = $service->verify('dom_123');

    expect($result['state'])->toBe('verified');
});

it('deletes an organization domain', function () {
    $mock = new MockHandler([
        new Response(204),
    ]);

    $service = new DomainService(makeDomainClient($mock));
    $service->delete('dom_123');

    expect($mock->count())->toBe(0);
});

it('throws on API error', function () {
    $mock = new MockHandler([
        new Response(404, [], json_encode(['message' => 'Not found'])),
    ]);

    $service = new DomainService(makeDomainClient($mock));
    $service->get('dom_bad');
})->throws(NotFoundException::class);

it('throws when domain verification feature is disabled', function () {
    config(['workos.features.domain_verification' => false]);

    app('workos')->domains();
})->throws(RuntimeException::class, 'WorkOS Domain Verification is not enabled');
