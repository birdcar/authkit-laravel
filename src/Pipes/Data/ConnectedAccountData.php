<?php

declare(strict_types=1);

namespace Authkit\Authkit\Pipes\Data;

use Authkit\Authkit\Pipes\AuthMethod;
use Authkit\Authkit\Pipes\ConnectedAccountState;
use InvalidArgumentException;
use WorkOS\Resource\DataIntegrationsListResponseData;

/**
 * One connected provider account for a user, mapped live from the WorkOS
 * list-providers response. There is no local projection behind this by
 * contract decision — every instance reflects WorkOS at the instant it was
 * asked.
 */
final readonly class ConnectedAccountData
{
    public function __construct(
        public string $id,
        public string $providerSlug,
        public ?string $providerName,
        public ?string $userId,
        public ?string $organizationId,
        public ConnectedAccountState $state,
        /** @var array<string> */
        public array $scopes,
        public ?AuthMethod $authMethod,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromProvider(DataIntegrationsListResponseData $provider): self
    {
        $account = $provider->connectedAccount;

        if ($account === null) {
            throw new InvalidArgumentException(sprintf(
                'Provider "%s" carries no connected account; PipesManager::connectedAccounts() filters these rows out before mapping.',
                $provider->slug,
            ));
        }

        return new self(
            id: $account->id,
            providerSlug: $provider->slug,
            providerName: $provider->name,
            userId: $account->userId,
            organizationId: $account->organizationId,
            state: ConnectedAccountState::fromSdk($account->state),
            scopes: $account->scopes,
            authMethod: $account->authMethod !== null ? AuthMethod::fromSdk($account->authMethod) : null,
            createdAt: $account->createdAt,
            updatedAt: $account->updatedAt,
        );
    }

    public function isConnected(): bool
    {
        return $this->state === ConnectedAccountState::Connected;
    }

    public function needsReauthorization(): bool
    {
        return $this->state === ConnectedAccountState::NeedsReauthorization;
    }
}
