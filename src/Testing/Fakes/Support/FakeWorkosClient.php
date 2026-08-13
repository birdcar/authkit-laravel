<?php

declare(strict_types=1);

namespace Authkit\Authkit\Testing\Fakes\Support;

use GuzzleHttp\HandlerStack;
use WorkOS\HttpClient;
use WorkOS\Service\ApiKeys;
use WorkOS\Service\UserManagement;
use WorkOS\WorkOS;

/**
 * The SDK client with its two API-key-bearing service accessors redirected to
 * the registry-backed fakes. Every other accessor is the stock implementation
 * over the same construction arguments, so unfaked managers resolving through
 * this client keep their real behavior (including the MockHandler harness's
 * HandlerStack, which rides in through $handler).
 *
 * @internal support type for ApiKeysFake — not part of the public testing API
 */
final class FakeWorkosClient extends WorkOS
{
    private readonly FakeApiKeysService $fakeApiKeys;

    private readonly FakeUserManagementService $fakeUserManagement;

    public function __construct(
        ApiKeyRegistry $registry,
        string $apiKey,
        string $clientId,
        string $baseUrl,
        int $timeout,
        int $maxRetries,
        ?HandlerStack $handler = null,
    ) {
        parent::__construct($apiKey, $clientId, $baseUrl, $timeout, $maxRetries, $handler);

        // The parent keeps its HttpClient private, so the fake services get an
        // equivalently-configured one of their own — identical behavior for
        // every inherited (non-overridden) service method.
        $httpClient = new HttpClient($apiKey, $clientId, $baseUrl, $timeout, $maxRetries, $handler);

        $this->fakeApiKeys = new FakeApiKeysService($httpClient, $registry);
        $this->fakeUserManagement = new FakeUserManagementService($httpClient, $registry);
    }

    public function apiKeys(): ApiKeys
    {
        return $this->fakeApiKeys;
    }

    public function userManagement(): UserManagement
    {
        return $this->fakeUserManagement;
    }
}
