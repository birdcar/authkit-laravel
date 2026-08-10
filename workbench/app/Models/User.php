<?php

namespace Workbench\App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Authkit\Authkit\Concerns\BelongsToWorkosOrganizations;
use Authkit\Authkit\Concerns\HasApiKeys;
use Authkit\Authkit\Concerns\HasWorkosUser;
use Authkit\Authkit\Contracts\WorkosUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Workbench\Database\Factories\UserFactory;

#[Fillable(['name', 'email', 'password', 'workos_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements WorkosUser
{
    /** @use HasFactory<UserFactory> */
    use BelongsToWorkosOrganizations, HasApiKeys, HasFactory, HasWorkosUser, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
