<?php

namespace Workbench\App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Authkit\Authkit\Concerns\BelongsToWorkosOrganizations;
use Authkit\Authkit\Concerns\HasApiKeys;
use Authkit\Authkit\Concerns\HasWorkosUser;
use Authkit\Authkit\Contracts\WorkosUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Workbench\Database\Factories\UserFactory;

class User extends Authenticatable implements WorkosUser
{
    /** @use HasFactory<UserFactory> */
    use BelongsToWorkosOrganizations, HasApiKeys, HasFactory, HasWorkosUser, Notifiable;

    // Property form, not the #[Fillable]/#[Hidden] attributes: those classes
    // only exist on Laravel 13.x and this workbench must boot on 12.x too.
    protected $fillable = ['name', 'email', 'password', 'workos_id'];

    protected $hidden = ['password', 'remember_token'];

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
