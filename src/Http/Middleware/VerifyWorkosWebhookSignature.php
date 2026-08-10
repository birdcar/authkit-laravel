<?php

declare(strict_types=1);

namespace Authkit\Authkit\Http\Middleware;

use Authkit\Authkit\Contracts\WorkosClientManager;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `authkit.webhook` alias: verifies the WorkOS-Signature header
 * (t=/v1= HMAC-SHA256, tolerance-checked) against the RAW request body and
 * stashes the verified, decoded payload for the controller. There is no code
 * path where an unsigned or wrongly-signed payload reaches the controller.
 */
final class VerifyWorkosWebhookSignature
{
    public const string PAYLOAD_ATTRIBUTE = 'authkit.webhook.payload';

    public function __construct(private readonly WorkosClientManager $clients) {}

    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('authkit.webhooks.secret');

        if (! is_string($secret) || trim($secret) === '') {
            // Fail fast naming the exact config key — a silently-skipped
            // verification (or a generic 401 pointing at WorkOS) would hide a
            // local configuration mistake.
            throw new RuntimeException(
                'The [authkit.webhooks.secret] config value is required to verify WorkOS webhook signatures. '
                .'Set WORKOS_WEBHOOK_SECRET in your .env file.',
            );
        }

        try {
            // getContent() on purpose: HMAC verification is byte-exact, so the
            // body must reach verifyEvent() untouched — parsing and
            // re-serializing would break signatures whenever key order or
            // whitespace differs from what WorkOS sent.
            $payload = $this->clients->client()->webhookVerification()->verifyEvent(
                eventBody: $request->getContent(),
                eventSignature: (string) $request->header('WorkOS-Signature'),
                secret: $secret,
                tolerance: (int) config('authkit.webhooks.tolerance', 180),
            );
        } catch (InvalidArgumentException) {
            // Malformed header, stale timestamp, or a hash mismatch — all
            // deliberately collapsed into one opaque 401.
            abort(401, 'Invalid WorkOS webhook signature.');
        }

        $request->attributes->set(self::PAYLOAD_ATTRIBUTE, $payload);

        return $next($request);
    }
}
