<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use WorkOS\AuthKit\WorkOS;

class ApiKeyUserProvider implements UserProvider
{
    public function retrieveById(mixed $identifier): ?Authenticatable
    {
        /** @var class-string $userModel */
        $userModel = config('workos.user_model', 'App\\Models\\User');

        if (! class_exists($userModel)) {
            return null;
        }

        if (method_exists($userModel, 'findByWorkOSId')) {
            /** @var Authenticatable|null */
            return $userModel::findByWorkOSId((string) $identifier);
        }

        /** @var Authenticatable|null */
        return $userModel::where('workos_id', $identifier)->first();
    }

    public function retrieveByToken(mixed $identifier, mixed $token): ?Authenticatable
    {
        $validation = app(WorkOS::class)->validateApiKey((string) $token);

        if ($validation === null) {
            return null;
        }

        return $this->retrieveById($validation->ownerId);
    }

    public function updateRememberToken(Authenticatable $user, mixed $token): void {}

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void {}
}
