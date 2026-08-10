<?php

declare(strict_types=1);

namespace Authkit\Authkit\JwtTemplates;

use Authkit\Authkit\Contracts\WorkosClientManager;
use Authkit\Authkit\Events\JwtTemplateUpdated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use WorkOS\Exception\NotFoundException;
use WorkOS\Resource\JWTTemplateResponse;

/**
 * JWT template passthrough, resolved via Authkit::jwtTemplate(). The loud
 * warning is the entire point: a template edit changes the claim set of
 * every subsequently-minted access token, and the sealed session cookie
 * carrying those claims has a 4KB browser ceiling. Every update() is loud —
 * there is deliberately no "no-op" special case for identical content.
 */
final class JwtTemplateManager
{
    public function __construct(private readonly WorkosClientManager $clients) {}

    public function get(): JWTTemplateResponse
    {
        return $this->clients->client()->userManagement()->listJWTTemplate();
    }

    public function update(string $content): JWTTemplateResponse
    {
        try {
            $previousContent = $this->get()->content;
        } catch (NotFoundException) {
            // WorkOS 404s the read until an environment has ever set a
            // template — the first write starts from an empty one.
            $previousContent = '';
        }

        $after = $this->clients->client()->userManagement()->updateJWTTemplate($content);

        Log::warning(
            'authkit: JWT template updated. Token claims and size may have changed. '
            .'This affects sealed-session parsing (workos guard), zero-HTTP RBAC claims, '
            .'and the Pennant feature_flags claim. The AuthKit sealed session cookie has a 4KB '
            .'ceiling — verify a real login end-to-end after this change before deploying it.',
        );

        Event::dispatch(new JwtTemplateUpdated($previousContent, $after->content));

        return $after;
    }
}
