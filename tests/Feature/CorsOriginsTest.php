<?php

declare(strict_types=1);

use Authkit\Authkit\CorsOrigins\CorsOriginManager;
use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use GuzzleHttp\Psr7\Response;
use WorkOS\Resource\CORSOriginResponse;

uses(UsesWorkosMockHandler::class)->group('depth-extensions');

// Test path: MockHandler — emulate 0.6.0 has a create route but no list
// route for CORS origins, and two branchless passthroughs only need a wire
// smoke (spec-phase-12 Component 3: feedback loop skipped as trivial).

/**
 * @return array<string, mixed>
 */
function corsOriginJson(string $id, string $origin): array
{
    return [
        'object' => 'cors_origin',
        'id' => $id,
        'origin' => $origin,
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ];
}

describe('CorsOrigins', function (): void {
    it('lists CORS origins and maps them onto SDK resources', function (): void {
        $this->fakeWorkosResponses([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'object' => 'list',
                'data' => [
                    corsOriginJson('cors_01', 'https://app.example.com'),
                    corsOriginJson('cors_02', 'https://admin.example.com'),
                ],
                'list_metadata' => ['before' => null, 'after' => null],
            ])),
        ]);

        $page = Authkit::corsOrigins()->list(limit: 2);

        $request = $this->workosRequestHistory[0]['request'];

        expect($page->data)->toHaveCount(2)
            ->and($page->data[0])->toBeInstanceOf(CORSOriginResponse::class)
            ->and($page->data[0]->origin)->toBe('https://app.example.com')
            ->and($request->getMethod())->toBe('GET')
            ->and($request->getUri()->getPath())->toBe('/user_management/cors_origins')
            ->and($request->getUri()->getQuery())->toContain('limit=2');
    });

    it('creates a CORS origin and returns the mapped resource', function (): void {
        $this->fakeWorkosResponses([
            new Response(201, ['Content-Type' => 'application/json'], (string) json_encode(
                corsOriginJson('cors_03', 'https://new.example.com'),
            )),
        ]);

        $created = Authkit::corsOrigins()->create('https://new.example.com');

        $request = $this->workosRequestHistory[0]['request'];

        expect($created->origin)->toBe('https://new.example.com')
            ->and($request->getMethod())->toBe('POST')
            ->and($request->getUri()->getPath())->toBe('/user_management/cors_origins')
            ->and(json_decode((string) $request->getBody(), true))->toBe(['origin' => 'https://new.example.com']);
    });

    it('exposes no delete method — the SDK has no CORS-origin delete endpoint', function (): void {
        // A scope boundary, not an omission (spec-phase-12 Deviations 1 /
        // Failure Mode 8): fabricating a delete would guess at an API shape
        // WorkOS has not shipped.
        expect(method_exists(CorsOriginManager::class, 'delete'))->toBeFalse();
    });
});
