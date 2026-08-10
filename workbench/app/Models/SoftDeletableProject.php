<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fixture documenting spec-phase-5 Failure Mode 8: Eloquent's `deleted` event
 * fires for soft deletes too, so HasWorkosResource deletes the remote FGA
 * resource while the local row remains restorable.
 */
class SoftDeletableProject extends Project
{
    use SoftDeletes;

    protected $table = 'projects';
}
