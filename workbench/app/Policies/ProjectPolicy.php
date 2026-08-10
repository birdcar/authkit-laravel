<?php

namespace Workbench\App\Policies;

use Authkit\Authkit\Policies\WorkosResourcePolicy;

/**
 * Discovered by Laravel's policy-name convention for
 * Workbench\App\Models\Project; the empty body is the point — __call on the
 * base class routes every ability through Authkit::check().
 */
class ProjectPolicy extends WorkosResourcePolicy {}
