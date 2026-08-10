<?php

declare(strict_types=1);

use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Enums\PortalIntent;
use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Tests\Concerns\UsesWorkosMockHandler;
use Authkit\Authkit\Tests\Support\EmulateServer;
use GuzzleHttp\Psr7\Response;
use Workbench\App\Models\Organization;
use WorkOS\Resource\GenerateLinkIntent;

// Test path: emulate — portal-link minting is fully emulate-covered
// (POST /portal/generate_link echoes intent + organization into the link).
// One MockHandler case asserts the request-body shape emulate does not echo.

uses(UsesWorkosMockHandler::class);

beforeEach(function (): void {
    $this->migratePackageDatabase();
});

afterEach(function (): void {
    if (isset($this->server)) {
        $this->server->stop();
    }
});

function startAdminPortalEmulate(): EmulateServer
{
    $server = new EmulateServer(port: 4192);
    $server->start();

    config()->set('authkit.emulate.enabled', true);
    config()->set('authkit.emulate.base_url', $server->baseUrl());
    app()->forgetInstance(WorkosClientManager::class);

    return $server;
}

describe('AdminPortal', function (): void {
    it('mints portal links for all seven intents from a raw organization id', function (): void {
        $this->server = startAdminPortalEmulate();

        foreach (PortalIntent::cases() as $intent) {
            $link = Authkit::portalLink('org_01HPORTAL', $intent);

            expect($link)->toBeString()
                ->and($link)->toContain("/portal/{$intent->value}/org_01HPORTAL");
        }
    })->skip(fn (): bool => ! EmulateServer::isAvailable(), 'npx/node not available');

    it('accepts an Eloquent org model exposing workos_id, with IT contact emails', function (): void {
        $this->server = startAdminPortalEmulate();

        $organization = Organization::query()->createQuietly(['name' => 'Acme Corp', 'workos_id' => 'org_01HMODEL']);

        $link = Authkit::portalLink($organization, PortalIntent::Dsync, itContactEmails: ['it@acme.com']);

        expect($link)->toContain('/portal/dsync/org_01HMODEL');
    })->skip(fn (): bool => ! EmulateServer::isAvailable(), 'npx/node not available');

    it('sends return_url, success_url, intent, and it_contact_emails on the wire', function (): void {
        $this->fakeWorkosResponses([
            new Response(201, ['Content-Type' => 'application/json'], '{"link": "https://setup.workos.com/portal/launch?secret=x"}'),
        ]);

        $link = Authkit::portalLink(
            'org_x',
            PortalIntent::AuditLogs,
            returnUrl: 'https://app.test/settings',
            successUrl: 'https://app.test/done',
            itContactEmails: ['it@acme.com', 'ops@acme.com'],
        );

        $request = $this->workosRequestHistory[0]['request'];
        $body = json_decode((string) $request->getBody(), true);

        expect($link)->toBe('https://setup.workos.com/portal/launch?secret=x')
            ->and($request->getMethod())->toBe('POST')
            ->and($request->getUri()->getPath())->toBe('/portal/generate_link')
            ->and($body)->toMatchArray([
                'organization' => 'org_x',
                'intent' => 'audit_logs',
                'return_url' => 'https://app.test/settings',
                'success_url' => 'https://app.test/done',
                'it_contact_emails' => ['it@acme.com', 'ops@acme.com'],
            ]);
    });

    it('refuses to mint a link for an organization model that has not synced to WorkOS', function (): void {
        $organization = Organization::query()->createQuietly(['name' => 'Unsynced Corp']);

        $this->fakeWorkosResponses([]);

        expect(fn (): string => Authkit::portalLink($organization, PortalIntent::Sso))
            ->toThrow(RuntimeException::class, 'workos_id is empty');

        expect($this->workosRequestHistory)->toHaveCount(0);
    });

    it('covers every SDK intent case with a package enum case', function (): void {
        $sdkValues = array_map(
            fn (GenerateLinkIntent $case): string => $case->value,
            GenerateLinkIntent::cases(),
        );
        $packageValues = array_map(
            fn (PortalIntent $case): string => $case->value,
            PortalIntent::cases(),
        );

        expect($packageValues)->toBe($sdkValues)
            ->and(PortalIntent::cases())->toHaveCount(7);

        foreach (PortalIntent::cases() as $case) {
            expect($case->toWorkos()->value)->toBe($case->value);
        }
    });
});
