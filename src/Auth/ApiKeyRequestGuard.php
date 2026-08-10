<?php

declare(strict_types=1);

namespace Authkit\Authkit\Auth;

use Illuminate\Auth\RequestGuard;
use Illuminate\Http\Request;

/**
 * RequestGuard whose per-request user memoization actually resets per request.
 * The stock class keeps $this->user when the container rebinds the request, so
 * in any multi-request process (Octane, embedded kernels, feature tests) the
 * second request would silently inherit the first request's principal — a
 * cross-request identity leak for a header-derived guard. WorkosGuard::
 * setRequest() established the package rule: swapping the request clears every
 * per-request conclusion.
 */
final class ApiKeyRequestGuard extends RequestGuard
{
    public function setRequest(Request $request): static
    {
        $this->user = null;

        parent::setRequest($request);

        return $this;
    }
}
