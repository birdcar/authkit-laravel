<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use WorkOS\AuthKit\Models\Concerns\HasOrganization;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSPermissions;

class User extends Authenticatable
{
    use HasFactory, HasOrganization, HasWorkOSId, HasWorkOSPermissions, Notifiable;

    protected $fillable = [
        'workos_id',
        'name',
        'email',
        'avatar_url',
    ];

    protected $hidden = [
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }

    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class);
    }

    public static function findOrCreateByWorkOS(array $data): static
    {
        $user = static::where('workos_id', $data['id'])
            ->orWhere('email', $data['email'] ?? null)
            ->first();

        $attributes = [
            'workos_id' => $data['id'],
            'email' => $data['email'] ?? null,
            'name' => trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')),
            'avatar_url' => $data['profile_picture_url'] ?? null,
        ];

        if ($user) {
            $user->update($attributes);

            return $user;
        }

        return static::create($attributes);
    }
}
