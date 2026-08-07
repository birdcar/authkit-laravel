<?php

declare(strict_types=1);

namespace Authkit\Authkit\Contracts;

use Authkit\Authkit\Auth\AccessTokenClaims;
use Authkit\Authkit\Concerns\HasWorkosUser;
use Illuminate\Contracts\Auth\Authenticatable;
use WorkOS\Resource\User as WorkosUserResource;

/**
 * Implemented by {@see HasWorkosUser}. Consumer code
 * types against this rather than against a concrete model, which is also what
 * lets the callback flow call `findOrCreateForWorkosUser()` on a class-string.
 */
interface WorkosUser extends Authenticatable
{
    public function claims(): ?AccessTokenClaims;

    public function setWorkosClaims(AccessTokenClaims $claims): void;

    /**
     * @return array<string, mixed>|null
     */
    public function impersonator(): ?array;

    /**
     * @param  array<string, mixed>|null  $impersonator
     */
    public function setWorkosImpersonator(?array $impersonator): void;

    public static function findOrCreateForWorkosUser(WorkosUserResource $workosUser): static;
}
