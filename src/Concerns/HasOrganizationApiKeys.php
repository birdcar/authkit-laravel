<?php

declare(strict_types=1);

namespace Authkit\Authkit\Concerns;

use Authkit\Authkit\Data\ApiKeyCreated;
use Authkit\Authkit\Data\ApiKeyMapper;
use Authkit\Authkit\Data\ApiKeySummary;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use WorkOS\RequestOptions;
use WorkOS\Resource\OrganizationApiKey;

/**
 * Organization-scoped WorkOS API keys for the consumer's org model: issue and
 * list keys owned by this organization. A request authenticating with one of
 * them resolves to a WorkosApiKeyActor wrapping this model.
 *
 * @phpstan-require-extends Model
 */
trait HasOrganizationApiKeys
{
    use InteractsWithWorkosApiKeys;

    /**
     * Create a new API key owned by this organization. The WorkOS API returns
     * the raw secret value ONLY in this response — it is never retrievable
     * again, from this method or any other. Persist $result->value
     * immediately or lose it.
     *
     * Not idempotent by default: pass $idempotencyKey in retry-prone contexts
     * (queued jobs, double-clickable admin actions) or a retry mints a
     * second, independently valid key.
     *
     * @param  list<string>|null  $permissions
     */
    public function createApiKey(
        string $name,
        ?array $permissions = null,
        ?DateTimeImmutable $expiresAt = null,
        ?string $idempotencyKey = null,
    ): ApiKeyCreated {
        $resource = $this->workosApiKeysClient()->apiKeys()->createOrganizationApiKey(
            organizationId: $this->workosApiKeyOwnerId(),
            name: $name,
            permissions: $permissions,
            expiresAt: $expiresAt,
            options: $idempotencyKey !== null ? new RequestOptions(idempotencyKey: $idempotencyKey) : null,
        );

        return ApiKeyMapper::fromCreated($resource);
    }

    /**
     * This organization's API keys as summaries — ApiKeySummary has no value
     * property at all, so a listing can never leak a raw secret.
     *
     * @return Collection<int, ApiKeySummary>
     */
    public function listApiKeys(): Collection
    {
        $page = $this->workosApiKeysClient()->apiKeys()->listOrganizationApiKeys(
            organizationId: $this->workosApiKeyOwnerId(),
        );

        return collect($page->data)
            ->whereInstanceOf(OrganizationApiKey::class)
            ->map(fn (OrganizationApiKey $key): ApiKeySummary => ApiKeyMapper::fromResource($key))
            ->values();
    }
}
