<?php

declare(strict_types=1);

namespace Authkit\Authkit\Authorization;

use Authkit\Authkit\Auth\WorkosApiKeyActor;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The API-key half of Gate integration: grants an ability when it matches a
 * permission carried by the API key that authenticated this request. Fires
 * only for API-key-sourced principals — a WorkosApiKeyActor (org-scoped key)
 * or a user the authkit-key guard tagged via setApiKeyPermissions()
 * (user-scoped key). Session/JWT-authenticated requests always fall through
 * to ClaimsGateHook and the app's policies: the two permission sources are
 * populated by mutually exclusive guards, so registration order between the
 * two hooks never matters.
 *
 * Same load-bearing constraint as ClaimsGateHook: this hook returns true or
 * null, NEVER false. A non-null before-result short-circuits every policy, so
 * an ability outside the key's permissions must defer (null), not deny.
 */
final class ApiKeyGateHook
{
    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __invoke(Authenticatable $user, string $ability, array $arguments = []): ?bool
    {
        $permissions = $this->apiKeyPermissions($user);

        if ($permissions === null) {
            return null; // not an API-key-authenticated principal this request — defer
        }

        return in_array($ability, $permissions, true) ? true : null; // NEVER false — see class docblock
    }

    /**
     * @return list<string>|null
     */
    private function apiKeyPermissions(Authenticatable $user): ?array
    {
        if ($user instanceof WorkosApiKeyActor) {
            return $user->permissions;
        }

        if (! method_exists($user, 'apiKeyPermissions')) {
            return null;
        }

        $permissions = $user->apiKeyPermissions();

        if (! is_array($permissions)) {
            return null;
        }

        return array_values(array_filter($permissions, 'is_string'));
    }
}
