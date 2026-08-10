<?php

declare(strict_types=1);

namespace Authkit\Authkit\Auth;

use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Data\ApiKeyMapper;
use Authkit\Authkit\Exceptions\MissingModelConfigurationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use WorkOS\Exception\WorkOSException;
use WorkOS\Resource\ApiKey;
use WorkOS\Resource\UserApiKeyOwner;

/**
 * The authkit-key guard's resolver: validates the presented key against the
 * WorkOS validate endpoint and resolves the local principal. Deliberately
 * uncached — the validate call IS the authn+authz check, so a revocation or
 * permission change takes effect on the very next request. (RequestGuard's
 * own per-request memoization means one validate call per request, which is
 * request-freshness, not a cross-request cache.)
 */
final class ApiKeyAuthenticator
{
    public function __construct(private readonly WorkosClientManager $clients) {}

    public function __invoke(Request $request): ?Authenticatable
    {
        $value = $this->extractKeyValue($request);

        if ($value === null) {
            return null;
        }

        try {
            $response = $this->clients->client()->apiKeys()->createValidation($value);
        } catch (WorkOSException $e) {
            // Fail closed: a validate-endpoint outage 401s keyed requests for
            // its duration (contract decision — no cache layer to fall back
            // on). The warning is what distinguishes this from a bad key.
            Log::warning('authkit: API key validation call failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $apiKey = $response->apiKey;

        if ($apiKey === null) {
            return null; // unrecognized, revoked, or expired key — WorkOS returns 200 with api_key: null
        }

        return $apiKey->owner instanceof UserApiKeyOwner
            ? $this->resolveUser($apiKey)
            : $this->resolveOrganizationActor($apiKey);
    }

    /**
     * 'bearer' reads the standard Authorization header; any other configured
     * value is treated as a literal header name (e.g. X-Api-Key).
     */
    private function extractKeyValue(Request $request): ?string
    {
        $header = config('authkit.api_keys.header', 'bearer');
        $header = is_string($header) && $header !== '' ? $header : 'bearer';

        $value = strcasecmp($header, 'bearer') === 0
            ? $request->bearerToken()
            : $request->header($header);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function resolveUser(ApiKey $apiKey): ?Authenticatable
    {
        $userModel = $this->userModel();
        $user = $userModel::query()->where('workos_id', $apiKey->owner->id)->first();

        if ($user !== null && ! $user instanceof Authenticatable) {
            // userModel() already vetted the class-string; this narrows the
            // fetched instance for static analysis with the same guarantee.
            throw MissingModelConfigurationException::forUserModel();
        }

        if ($user === null) {
            // Data shadow, not an invalid key: WorkOS vouched for the key but
            // the local projection has no matching row (events lag, or the
            // user never logged in here). The guard does not auto-provision —
            // that is the login flow's job (projection-boundary doctrine).
            Log::warning('authkit: API key validated by WorkOS but no local user projection exists', [
                'workos_user_id' => $apiKey->owner->id,
            ]);

            return null;
        }

        if (method_exists($user, 'setApiKeyPermissions')) {
            $user->setApiKeyPermissions(ApiKeyMapper::stringPermissions($apiKey->permissions));
        }

        return $user;
    }

    private function resolveOrganizationActor(ApiKey $apiKey): ?Authenticatable
    {
        $organizationModel = $this->organizationModel();
        $organization = $organizationModel::query()->where('workos_id', $apiKey->owner->id)->first();

        if ($organization === null) {
            // Same data-shadow reasoning as resolveUser() — most likely an org
            // created directly in WorkOS that never synced locally.
            Log::warning('authkit: API key validated by WorkOS but no local organization projection exists', [
                'workos_organization_id' => $apiKey->owner->id,
            ]);

            return null;
        }

        return new WorkosApiKeyActor(
            organization: $organization,
            permissions: ApiKeyMapper::stringPermissions($apiKey->permissions),
            apiKeyId: $apiKey->id,
            expiresAt: $apiKey->expiresAt,
        );
    }

    /**
     * Resolved through the same chain the session guard and projection
     * listeners use (AuthKitController::userModel()), so a key-authenticated
     * request retrieves exactly the row the rest of the package maintains.
     * Must be Authenticatable — the guard returns it as the request principal.
     *
     * @return class-string<Model&Authenticatable>
     */
    private function userModel(): string
    {
        $model = config('auth.providers.workos.model', config('auth.providers.users.model'));

        if (! is_string($model)
            || ! class_exists($model)
            || ! is_a($model, Model::class, true)
            || ! is_a($model, Authenticatable::class, true)) {
            throw MissingModelConfigurationException::forUserModel();
        }

        return $model;
    }

    /**
     * @return class-string<Model>
     */
    private function organizationModel(): string
    {
        $model = config('authkit.organization.model');

        if (! is_string($model) || ! class_exists($model) || ! is_a($model, Model::class, true)) {
            throw MissingModelConfigurationException::forOrganizationModel();
        }

        return $model;
    }
}
