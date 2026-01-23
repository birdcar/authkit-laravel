<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use WorkOS\AuthKit\Models\Concerns\HasWorkOSId;

class Organization extends Model
{
    use HasFactory, HasWorkOSId;

    protected $fillable = [
        'workos_id',
        'name',
        'slug',
        'domains',
    ];

    protected function casts(): array
    {
        return [
            'domains' => 'array',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class);
    }

    public static function findOrCreateByWorkOS(array $data): static
    {
        return static::updateOrCreate(
            ['workos_id' => $data['id']],
            [
                'name' => $data['name'],
                'slug' => Str::slug($data['name']),
                'domains' => $data['domains'] ?? [],
            ]
        );
    }
}
