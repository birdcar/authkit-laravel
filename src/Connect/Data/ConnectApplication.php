<?php

declare(strict_types=1);

namespace Authkit\Authkit\Connect\Data;

use DateTimeImmutable;
use WorkOS\Resource\ConnectApplication as SdkConnectApplication;
use WorkOS\Resource\ConnectApplicationRedirectUri;

/**
 * Package-owned mirror of the SDK's generic ConnectApplication resource, so
 * ConnectManager never returns an SDK type (spec-phase-10 §6.6). The SDK's
 * narrower ConnectApplicationOAuth/ConnectApplicationM2M subtypes are declared
 * in its Resource/ directory but never actually returned by Service\Connect,
 * so this single DTO covers every application shape.
 */
final readonly class ConnectApplication
{
    public function __construct(
        public string $id,
        public string $clientId,
        public string $name,
        public ?string $description,
        /** @var array<int, string> */
        public array $scopes,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?string $applicationType = null,
        /** @var array<int, array{uri: string, default: bool}>|null */
        public ?array $redirectUris = null,
        public ?bool $usesPkce = null,
        public ?bool $isFirstParty = null,
        public ?bool $wasDynamicallyRegistered = null,
        public ?string $organizationId = null,
    ) {}

    public static function fromSdk(SdkConnectApplication $application): self
    {
        $redirectUris = null;

        if ($application->redirectUris !== null) {
            $redirectUris = array_map(
                static fn (ConnectApplicationRedirectUri $redirectUri): array => [
                    'uri' => $redirectUri->uri,
                    'default' => $redirectUri->default,
                ],
                array_values($application->redirectUris),
            );
        }

        return new self(
            id: $application->id,
            clientId: $application->clientId,
            name: $application->name,
            description: $application->description,
            scopes: array_values(array_filter($application->scopes, 'is_string')),
            createdAt: $application->createdAt,
            updatedAt: $application->updatedAt,
            applicationType: $application->applicationType,
            redirectUris: $redirectUris,
            usesPkce: $application->usesPkce,
            isFirstParty: $application->isFirstParty,
            wasDynamicallyRegistered: $application->wasDynamicallyRegistered,
            organizationId: $application->organizationId,
        );
    }
}
