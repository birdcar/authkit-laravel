<?php

declare(strict_types=1);

namespace Authkit\Authkit\Concerns;

use Authkit\Authkit\Contracts\WorkosClientManager;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use WorkOS\WorkOS;

/**
 * The owner-independent remainder shared by HasApiKeys (User) and
 * HasOrganizationApiKeys (org model): revocation is keyed by API key ID alone
 * on the WorkOS side, identical for both owner types.
 *
 * @phpstan-require-extends Model
 */
trait InteractsWithWorkosApiKeys
{
    /**
     * Revoke (permanently delete) an API key by ID. WorkOS's delete endpoint
     * is keyed by API key ID alone — it takes no owner parameter and performs
     * no ownership check against $this. The caller MUST verify $apiKeyId
     * actually belongs to this user/organization before calling; nothing here
     * stops $org->revokeApiKey($someoneElsesKeyId) from succeeding.
     */
    public function revokeApiKey(string $apiKeyId): void
    {
        $this->workosApiKeysClient()->apiKeys()->deleteApiKey($apiKeyId);
    }

    /**
     * Resolved per call (not memoized) so the test harness's mid-test client
     * swap is always picked up.
     */
    private function workosApiKeysClient(): WorkOS
    {
        return app(WorkosClientManager::class)->client();
    }

    /**
     * This model's own WorkOS ID, required before any API key operation can
     * be attributed to it.
     */
    private function workosApiKeyOwnerId(): string
    {
        $workosId = $this->getAttribute('workos_id');

        if (! is_string($workosId) || $workosId === '') {
            $key = $this->getKey();

            throw new RuntimeException(sprintf(
                'Cannot manage API keys for %s [%s]: it has no workos_id yet. '
                .'The model must be synced with WorkOS before it can own API keys.',
                static::class,
                is_scalar($key) ? (string) $key : '',
            ));
        }

        return $workosId;
    }
}
