<?php

namespace Workbench\App\Models;

use Authkit\Authkit\Concerns\HasAuditLogs;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
#[Fillable(['title', 'body'])]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasAuditLogs, HasFactory, SoftDeletes;
}
