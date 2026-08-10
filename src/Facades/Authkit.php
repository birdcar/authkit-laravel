<?php

declare(strict_types=1);

namespace Authkit\Authkit\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static ?\Illuminate\Database\Eloquent\Model currentOrganization()
 * @method static \Authkit\Authkit\Authorization\RoleManager roles()
 * @method static \Authkit\Authkit\Authorization\PermissionManager permissions()
 * @method static \Authkit\Authkit\Authorization\ResourceManager resources()
 * @method static \Authkit\Authkit\Authorization\FgaChecker fga()
 * @method static \Authkit\Authkit\Connect\ConnectManager connect()
 * @method static \Authkit\Authkit\Invitations\InvitationManager invitations()
 * @method static \Authkit\Authkit\JwtTemplates\JwtTemplateManager jwtTemplate()
 * @method static \Authkit\Authkit\CorsOrigins\CorsOriginManager corsOrigins()
 * @method static \Authkit\Authkit\Groups\GroupManager groups()
 * @method static \Authkit\Authkit\Pipes\PipesManager pipes()
 * @method static string portalLink(\Illuminate\Database\Eloquent\Model|string $organization, \Authkit\Authkit\Enums\PortalIntent $intent, ?string $returnUrl = null, ?string $successUrl = null, array<int, string>|null $itContactEmails = null)
 * @method static bool check(string $permissionSlug, string $resourceExternalId, string $resourceTypeSlug, ?string $organizationMembershipId = null, ?\Illuminate\Contracts\Auth\Authenticatable $user = null, ?string $organizationId = null, ?\WorkOS\RequestOptions $options = null)
 *
 * @see \Authkit\Authkit\Authkit
 */
class Authkit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Authkit\Authkit\Authkit::class;
    }
}
