<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use WorkOS\AuthKit\Services\DomainService;

beforeEach(function () {
    config([
        'workos.api_key' => 'sk_test_domain',
        'workos.widgets.base_url' => 'https://api.workos.com',
    ]);
});

it('creates an organization domain', function () {
    Http::fake([
        'api.workos.com/organization_domains' => Http::response([
            'id' => 'dom_123',
            'domain' => 'example.com',
            'state' => 'pending',
            'verification_token' => 'tok_abc',
            'verification_prefix' => '_workos',
        ]),
    ]);

    $service = new DomainService;
    $result = $service->create('org_456', 'example.com');

    expect($result['id'])->toBe('dom_123')
        ->and($result['state'])->toBe('pending')
        ->and($result['verification_token'])->toBe('tok_abc');

    Http::assertSent(function ($request) {
        return $request['organization_id'] === 'org_456'
            && $request['domain'] === 'example.com';
    });
});

it('gets an organization domain', function () {
    Http::fake([
        'api.workos.com/organization_domains/dom_123' => Http::response([
            'id' => 'dom_123',
            'domain' => 'example.com',
            'state' => 'verified',
        ]),
    ]);

    $service = new DomainService;
    $result = $service->get('dom_123');

    expect($result['state'])->toBe('verified');
});

it('verifies an organization domain', function () {
    Http::fake([
        'api.workos.com/organization_domains/dom_123/verify' => Http::response([
            'id' => 'dom_123',
            'state' => 'verified',
        ]),
    ]);

    $service = new DomainService;
    $result = $service->verify('dom_123');

    expect($result['state'])->toBe('verified');
});

it('deletes an organization domain', function () {
    Http::fake([
        'api.workos.com/organization_domains/dom_123' => Http::response(null, 204),
    ]);

    $service = new DomainService;
    $service->delete('dom_123');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'organization_domains/dom_123')
            && $request->method() === 'DELETE';
    });
});

it('throws on API error', function () {
    Http::fake([
        'api.workos.com/organization_domains/dom_bad' => Http::response(['error' => 'not found'], 404),
    ]);

    $service = new DomainService;
    $service->get('dom_bad');
})->throws(RuntimeException::class, 'WorkOS Domain API error');

it('throws when domain verification feature is disabled', function () {
    config(['workos.features.domain_verification' => false]);

    app('workos')->domains();
})->throws(RuntimeException::class, 'WorkOS Domain Verification is not enabled');
