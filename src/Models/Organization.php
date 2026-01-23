<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $workos_id
 * @property string $name
 * @property string|null $slug
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static static|null where(string $column, mixed $value)
 * @method static static firstOrCreate(array<string, mixed> $attributes, array<string, mixed> $values = [])
 */
class Organization extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'workos_id',
        'name',
        'slug',
    ];

    /**
     * @return BelongsToMany<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function users(): BelongsToMany
    {
        /** @var class-string<\Illuminate\Database\Eloquent\Model> $userModel */
        $userModel = config('workos.user_model');

        return $this->belongsToMany(
            $userModel,
            'organization_user',
            'organization_id',
            'user_id'
        )->withPivot('role')->withTimestamps();
    }

    public static function findByWorkOSId(string $workosId): ?static
    {
        /** @var static|null $result */
        $result = static::query()->where('workos_id', $workosId)->first();

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function findOrCreateByWorkOS(array $data): static
    {
        /** @var static $result */
        $result = static::query()->firstOrCreate(
            ['workos_id' => $data['id']],
            [
                'name' => $data['name'],
                'slug' => $data['slug'] ?? null,
            ]
        );

        return $result;
    }

    public function syncFromWorkOS(): void
    {
        $organizations = new \WorkOS\Organizations;
        $orgData = $organizations->getOrganization($this->workos_id);

        $this->update([
            'name' => $orgData->raw['name'] ?? $this->name,
            'slug' => $orgData->raw['slug'] ?? null,
        ]);
    }
}
