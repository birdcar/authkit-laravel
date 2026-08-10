<?php

declare(strict_types=1);

namespace Authkit\Authkit\Concerns;

use Authkit\Authkit\Data\ApiKeyCreated;
use Authkit\Authkit\Data\ApiKeyMapper;
use Authkit\Authkit\Data\ApiKeySummary;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RuntimeException;
use WorkOS\RequestOptions;
use WorkOS\Resource\UserApiKey;

/**
 * User-scoped WorkOS API keys for the consumer's User model: issue and list
 * keys owned by this user, plus the guard-facing permission attachment the
 * authkit-key guard sets when a request authenticates with one of them.
 *
 * @phpstan-require-extends Model
 */
trait HasApiKeys
{
    use InteractsWithWorkosApiKeys;

    /**
     * Never persisted, never a source of JWT claims — set per request by the
     * authkit-key guard, null everywhere else.
     *
     * @var list<string>|null
     */
    protected ?array $workosApiKeyPermissions = null;

    /**
     * The permissions of the API key that authenticated this request, when
     * this User was resolved by the authkit-key guard from a user-scoped key.
     * Null on every other request (session/JWT auth, or no auth at all).
     *
     * @return list<string>|null
     */
    public function apiKeyPermissions(): ?array
    {
        return $this->workosApiKeyPermissions;
    }

    /**
     * @param  list<string>  $permissions
     */
    public function setApiKeyPermissions(array $permissions): static
    {
        $this->workosApiKeyPermissions = $permissions;

        return $this;
    }

    /**
     * Create a new API key for this user, scoped to one organization
     * membership (WorkOS requires the organization for user keys — the user
     * must have an active membership in it). The WorkOS API returns the raw
     * secret value ONLY in this response — it is never retrievable again,
     * from this method or any other. Persist $result->value immediately or
     * lose it.
     *
     * Not idempotent by default: pass $idempotencyKey in retry-prone contexts
     * (queued jobs, double-clickable admin actions) or a retry mints a
     * second, independently valid key.
     *
     * @param  list<string>|null  $permissions
     */
    public function createApiKey(
        string $name,
        Model|string $organization,
        ?array $permissions = null,
        ?DateTimeImmutable $expiresAt = null,
        ?string $idempotencyKey = null,
    ): ApiKeyCreated {
        $resource = $this->workosApiKeysClient()->userManagement()->createUserApiKey(
            userId: $this->workosApiKeyOwnerId(),
            name: $name,
            organizationId: $this->workosApiKeyOrganizationId($organization),
            permissions: $permissions,
            expiresAt: $expiresAt,
            options: $idempotencyKey !== null ? new RequestOptions(idempotencyKey: $idempotencyKey) : null,
        );

        return ApiKeyMapper::fromCreated($resource);
    }

    /**
     * This user's API keys as summaries — ApiKeySummary has no value property
     * at all, so a listing can never leak a raw secret. Optionally scoped to
     * one organization.
     *
     * @return Collection<int, ApiKeySummary>
     */
    public function listApiKeys(Model|string|null $organization = null): Collection
    {
        $page = $this->workosApiKeysClient()->userManagement()->listUserApiKeys(
            userId: $this->workosApiKeyOwnerId(),
            organizationId: $organization === null ? null : $this->workosApiKeyOrganizationId($organization),
        );

        return collect($page->data)
            ->whereInstanceOf(UserApiKey::class)
            ->map(fn (UserApiKey $key): ApiKeySummary => ApiKeyMapper::fromResource($key))
            ->values();
    }

    /**
     * Accepts the org model itself (its workos_id is read, with a loud
     * failure when it never synced) or a raw WorkOS organization ID string.
     */
    private function workosApiKeyOrganizationId(Model|string $organization): string
    {
        if (is_string($organization)) {
            return $organization;
        }

        $workosId = $organization->getAttribute('workos_id');

        if (! is_string($workosId) || $workosId === '') {
            $key = $organization->getKey();

            throw new RuntimeException(sprintf(
                'Cannot scope an API key to %s [%s]: it has no workos_id yet. '
                .'Pass a synced organization model or a raw WorkOS organization ID.',
                $organization::class,
                is_scalar($key) ? (string) $key : '',
            ));
        }

        return $workosId;
    }
}
