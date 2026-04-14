<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Services;

use WorkOS\Resource\RadarAction;
use WorkOS\Resource\RadarStandaloneAssessRequestAction;
use WorkOS\Resource\RadarStandaloneAssessRequestAuthMethod;
use WorkOS\Resource\RadarType;
use WorkOS\WorkOS;

class RadarService
{
    public function __construct(
        private readonly WorkOS $client,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function createAttempt(array $attributes): array
    {
        $result = $this->client->radar()->createAttempt(
            ipAddress: (string) ($attributes['ip_address'] ?? ''),
            userAgent: (string) ($attributes['user_agent'] ?? ''),
            email: (string) ($attributes['email'] ?? ''),
            authMethod: RadarStandaloneAssessRequestAuthMethod::from((string) ($attributes['auth_method'] ?? 'password')),
            action: RadarStandaloneAssessRequestAction::from((string) ($attributes['action'] ?? 'sign_in')),
            deviceFingerprint: $attributes['device_fingerprint'] ?? null,
            botScore: $attributes['bot_score'] ?? null,
        );

        return $result->toArray();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function updateAttempt(string $id, array $attributes): array
    {
        /** @var array<string, mixed> */
        return $this->client->radar()->updateAttempt(
            id: $id,
            challengeStatus: $attributes['challenge_status'] ?? null,
            attemptStatus: $attributes['attempt_status'] ?? null,
        ) ?? [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function addToList(array $attributes): array
    {
        $result = $this->client->radar()->addListEntry(
            type: RadarType::from((string) ($attributes['type'] ?? '')),
            action: RadarAction::from((string) ($attributes['action'] ?? '')),
            entry: (string) ($attributes['entry'] ?? ''),
        );

        return $result->toArray();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function removeFromList(array $attributes): void
    {
        $this->client->radar()->removeListEntry(
            type: RadarType::from((string) ($attributes['type'] ?? '')),
            action: RadarAction::from((string) ($attributes['action'] ?? '')),
            entry: (string) ($attributes['entry'] ?? ''),
        );
    }
}
