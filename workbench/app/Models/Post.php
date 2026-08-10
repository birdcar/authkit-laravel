<?php

namespace Workbench\App\Models;

use Authkit\Authkit\Concerns\HasAuditLogs;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Workbench\Database\Factories\PostFactory;

/**
 * Consumer-example model for HasAuditLogs on the default convention:
 * lifecycle actions audit as "post.create", "post.update", "post.archive"
 * (soft delete), "post.delete" (force delete), and "post.restore" — no
 * per-model configuration, and no WorkOS SDK reference anywhere in sight.
 */
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasAuditLogs, HasFactory, SoftDeletes;

    // Property form, not #[Fillable]: the attribute class only exists on
    // Laravel 13.x and this workbench must boot on 12.x too.
    protected $fillable = ['title', 'body'];
}
