<?php

declare(strict_types=1);

use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Events\JwtTemplateUpdated;
use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use Authkit\Authkit\Tests\Support\EmulateServer;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

uses(UsesWorkosMockHandler::class)->group('depth-extensions');

// Test path: emulate — JWT template coverage is SOLID (GET/PUT with template
// validation and real 404-before-first-set semantics). One MockHandler case
// covers the fresh-environment first write, which the seeded emulate cannot.

const JWT_TEMPLATE_SEEDED = '{"plan": "{{ organization.name }}"}';

afterEach(function (): void {
    if (isset($this->server)) {
        $this->server->stop();
    }
});

function startJwtTemplateEmulate(): EmulateServer
{
    $server = new EmulateServer(
        port: 4190,
        seedPath: __DIR__.'/../Fixtures/workos-emulate-depth.config.yaml',
    );
    $server->start();

    config()->set('authkit.emulate.enabled', true);
    config()->set('authkit.emulate.base_url', $server->baseUrl());
    app()->forgetInstance(WorkosClientManager::class);

    return $server;
}

describe('JwtTemplate', function (): void {
    it('reads the seeded template and rewrites it loudly: warning logged, event dispatched with the before/after diff', function (): void {
        $this->server = startJwtTemplateEmulate();

        expect(Authkit::jwtTemplate()->get()->content)->toBe(JWT_TEMPLATE_SEEDED);

        Log::spy();
        Event::fake([JwtTemplateUpdated::class]);

        $updated = Authkit::jwtTemplate()->update('{"tier": "{{ organization.name }}"}');

        expect($updated->content)->toBe('{"tier": "{{ organization.name }}"}')
            ->and(Authkit::jwtTemplate()->get()->content)->toBe('{"tier": "{{ organization.name }}"}');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, 'JWT template updated')
                && str_contains($message, '4KB'));

        Event::assertDispatched(
            JwtTemplateUpdated::class,
            fn (JwtTemplateUpdated $event): bool => $event->previousContent === JWT_TEMPLATE_SEEDED
                && $event->newContent === '{"tier": "{{ organization.name }}"}',
        );
    })->skip(fn (): bool => ! EmulateServer::isAvailable(), 'npx/node not available');

    it('stays loud for every write, including identical content and growing claim sets', function (): void {
        $this->server = startJwtTemplateEmulate();

        Log::spy();
        Event::fake([JwtTemplateUpdated::class]);

        // Every write is loud — no "no-op" special case: an identical-content
        // update, a small template, and one that adds several extra claims
        // all fire the warning and carry the correct before/after diff.
        $contents = [
            JWT_TEMPLATE_SEEDED, // identical to the seeded content
            '{"plan": "pro"}',
            '{"plan": "{{ organization.name }}", "email": "{{ user.email }}", "name": "{{ user.first_name }} {{ user.last_name }}", "locale": "en-US", "beta": "yes"}',
        ];

        $previous = JWT_TEMPLATE_SEEDED;

        foreach ($contents as $content) {
            Authkit::jwtTemplate()->update($content);

            $expectedPrevious = $previous;

            Event::assertDispatched(
                JwtTemplateUpdated::class,
                fn (JwtTemplateUpdated $event): bool => $event->previousContent === $expectedPrevious
                    && $event->newContent === $content,
            );

            $previous = $content;
        }

        Log::shouldHaveReceived('warning')
            ->times(count($contents))
            ->withArgs(fn (string $message): bool => str_contains($message, 'JWT template updated'));
    })->skip(fn (): bool => ! EmulateServer::isAvailable(), 'npx/node not available');

    it('treats the first-ever write on a fresh environment as starting from an empty template', function (): void {
        // Real WorkOS 404s the template read until one has ever been set —
        // the first update() must not explode on its own before-read.
        $this->fakeWorkosResponses([
            new Response(404, ['Content-Type' => 'application/json'], '{"message": "JWT template not found", "code": "not_found"}'),
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'object' => 'jwt_template',
                'content' => '{"plan": "free"}',
                'created_at' => '2026-01-01T00:00:00Z',
                'updated_at' => '2026-01-01T00:00:00Z',
            ])),
        ]);

        Event::fake([JwtTemplateUpdated::class]);

        $updated = Authkit::jwtTemplate()->update('{"plan": "free"}');

        expect($updated->content)->toBe('{"plan": "free"}');

        $put = $this->workosRequestHistory[1]['request'];

        expect($put->getMethod())->toBe('PUT')
            ->and($put->getUri()->getPath())->toBe('/user_management/jwt_template')
            ->and(json_decode((string) $put->getBody(), true))->toBe(['content' => '{"plan": "free"}']);

        Event::assertDispatched(
            JwtTemplateUpdated::class,
            fn (JwtTemplateUpdated $event): bool => $event->previousContent === ''
                && $event->newContent === '{"plan": "free"}',
        );
    });
});
