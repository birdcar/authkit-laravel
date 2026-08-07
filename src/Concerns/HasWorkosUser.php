<?php

declare(strict_types=1);

namespace Authkit\Authkit\Concerns;

use Authkit\Authkit\Auth\AccessTokenClaims;
use Authkit\Authkit\Contracts\WorkosUser;
use Authkit\Authkit\Exceptions\UnverifiedEmailCollisionException;
use Illuminate\Database\Eloquent\Model;
use WorkOS\Resource\User as WorkosUserResource;
use WorkOS\Service\UserManagement;

/**
 * Satisfies {@see WorkosUser} for the consumer's User model.
 *
 * @phpstan-require-extends Model
 */
trait HasWorkosUser
{
    // Plain declared properties, not Eloquent attributes: a declared property
    // short-circuits __get/__set, so these never reach $attributes or persistence.
    private ?AccessTokenClaims $workosClaims = null;

    /** @var array<string, mixed>|null */
    private ?array $workosImpersonator = null;

    public function claims(): ?AccessTokenClaims
    {
        return $this->workosClaims;
    }

    public function setWorkosClaims(AccessTokenClaims $claims): void
    {
        $this->workosClaims = $claims;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function impersonator(): ?array
    {
        return $this->workosImpersonator;
    }

    /**
     * @param  array<string, mixed>|null  $impersonator
     */
    public function setWorkosImpersonator(?array $impersonator): void
    {
        $this->workosImpersonator = $impersonator;
    }

    public static function findOrCreateForWorkosUser(WorkosUserResource $workosUser): static
    {
        /** @var static|null $user */
        $user = static::query()->firstWhere('workos_id', $workosUser->id);

        // Upgrade path: an account that predates WorkOS adoption gets linked on
        // first login rather than duplicated. Gated on emailVerified — if the
        // environment allows sign-up with an unverified address, matching on email
        // alone would hand an attacker who registers victim@corp.com the victim's
        // existing local account and everything attached to it.
        if ($user === null && $workosUser->emailVerified === true) {
            /** @var static|null $user */
            $user = static::query()->firstWhere('email', $workosUser->email);
        }

        // Refusing to link is only half an answer: a users table with a unique
        // email (Laravel's default) cannot hold a second row either, so creating
        // one would surface as an opaque integrity-constraint violation mid-login.
        if ($user === null && static::query()->where('email', $workosUser->email)->exists()) {
            throw new UnverifiedEmailCollisionException($workosUser->id, (string) $workosUser->email);
        }

        if ($user === null) {
            $user = static::query()->newModelInstance();
            // forceFill rather than fill: this is trusted data coming straight from
            // WorkOS, and it must land regardless of how the consumer's model
            // declares $fillable.
            $user->forceFill([
                'email' => $workosUser->email,
                'name' => $workosUser->name ?? trim(($workosUser->firstName ?? '').' '.($workosUser->lastName ?? '')),
            ]);
        }

        $user->forceFill(['workos_id' => $workosUser->id])->save();

        $key = $user->getKey();
        $localId = is_int($key) || is_string($key) ? (string) $key : null;

        // Skipped when WorkOS already has the right value, so this costs an API
        // write on first link only rather than on every login.
        if ($localId !== null && $workosUser->externalId !== $localId) {
            app(UserManagement::class)->updateUser(
                id: $workosUser->id,
                externalId: $localId,
            );
        }

        return $user;
    }
}
