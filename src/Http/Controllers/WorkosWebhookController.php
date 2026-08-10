<?php

declare(strict_types=1);

namespace Authkit\Authkit\Http\Controllers;

use Authkit\Authkit\Events\WorkosEventMapper;
use Authkit\Authkit\Http\Middleware\VerifyWorkosWebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use WorkOS\Resource\EventSchema;

/**
 * Webhooks are the low-latency transport, not a second source of truth: the
 * verified payload is re-hydrated into the SAME EventSchema shape the poller
 * receives and mapped through the SAME WorkosEventMapper, so both transports
 * dispatch identical Laravel event objects by construction.
 */
final class WorkosWebhookController
{
    public function __construct(private readonly WorkosEventMapper $mapper) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->attributes->get(VerifyWorkosWebhookSignature::PAYLOAD_ATTRIBUTE);

        // Only the signature middleware writes this attribute, so a non-array
        // here means the route was registered without it — a wiring bug worth
        // failing loudly on, never a payload to guess at.
        abort_unless(is_array($payload), 500, 'Webhook payload missing — is the authkit.webhook middleware applied?');

        event($this->mapper->map(EventSchema::fromArray($payload)));

        return response()->json(['received' => true]);
    }
}
