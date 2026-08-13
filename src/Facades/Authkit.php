<?php

declare(strict_types=1);

namespace Authkit\Authkit\Facades;

use Authkit\Authkit\Testing\AuthkitFake;
use Authkit\Authkit\Testing\FakesWorkosSession;
use Authkit\Authkit\Testing\FakeWorkosGuard;
use Illuminate\Contracts\Auth\Authenticatable;
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

    /**
     * Authenticate the `workos` guard as $user for the rest of the test —
     * `actingAs` for WorkOS sessions, with no cookie, JWKS fetch or SDK call.
     *
     * ```php
     * Authkit::actingAs($user, [
     *     'organization' => $team,
     *     'role' => 'admin',
     *     'permissions' => ['projects.delete'],
     *     'feature_flags' => ['team-plan'],
     * ]);
     * ```
     *
     * Keys the friendly handling doesn't recognise merge into the token
     * payload verbatim: `Authkit::actingAs($user, ['sid' => 'session_1'])`.
     *
     * The `workos` guard also becomes the default for the rest of the test,
     * so `auth()->user()` and `$request->user()` see the acting user — the
     * same thing Laravel's own `actingAs` does.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function actingAs(Authenticatable $user, array $claims = []): Authenticatable
    {
        return FakesWorkosSession::actingAs($user, $claims);
    }

    /**
     * Install an explicitly unauthenticated `workos` guard, for proving a
     * route rejects guests.
     */
    public static function actingAsGuest(): FakeWorkosGuard
    {
        return FakesWorkosSession::actingAsGuest();
    }

    /**
     * Swap manager bindings for in-memory fakes and get back the scripting/
     * assertion handle.
     *
     * ```php
     * $fake = Authkit::fake();                    // fake every manager
     * $fake = Authkit::fake(['fga', 'vault']);    // fake only these — the rest stay real
     *
     * $fake->fga()->allow('projects.view', $project)->deny('projects.delete', $project);
     * $fake->fga()->assertChecked('projects.view', $project);
     * ```
     *
     * Application code keeps calling this facade and the managers exactly as
     * in production; only the container bindings change underneath it.
     *
     * @param  list<string>|null  $managers  null fakes everything; see AuthkitFake::MANAGERS
     */
    public static function fake(?array $managers = null): AuthkitFake
    {
        return new AuthkitFake($managers);
    }
}
