<?php

namespace Workbench\App\Models;

use Authkit\Authkit\Casts\Vaulted;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixture model exercising the Vaulted cast against a real Eloquent lifecycle.
 */
class VaultDemoRecord extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => Vaulted::class,
        ];
    }
}
