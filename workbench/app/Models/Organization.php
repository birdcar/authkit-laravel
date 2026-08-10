<?php

namespace Workbench\App\Models;

use Authkit\Authkit\Concerns\HasWorkosOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Workbench\Database\Factories\OrganizationFactory;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasWorkosOrganization;

    protected $guarded = ['id'];

    protected static function newFactory(): OrganizationFactory
    {
        return OrganizationFactory::new();
    }
}
