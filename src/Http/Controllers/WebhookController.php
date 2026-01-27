<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use WorkOS\AuthKit\Events\WebhookReceived;
use WorkOS\AuthKit\Events\Webhooks\WorkOSMembershipCreated;
use WorkOS\AuthKit\Events\Webhooks\WorkOSMembershipDeleted;
use WorkOS\AuthKit\Events\Webhooks\WorkOSMembershipUpdated;
use WorkOS\AuthKit\Events\Webhooks\WorkOSOrganizationCreated;
use WorkOS\AuthKit\Events\Webhooks\WorkOSOrganizationDeleted;
use WorkOS\AuthKit\Events\Webhooks\WorkOSOrganizationUpdated;
use WorkOS\AuthKit\Events\Webhooks\WorkOSSessionCreated;
use WorkOS\AuthKit\Events\Webhooks\WorkOSSessionRevoked;
use WorkOS\AuthKit\Events\Webhooks\WorkOSUserCreated;
use WorkOS\AuthKit\Events\Webhooks\WorkOSUserDeleted;
use WorkOS\AuthKit\Events\Webhooks\WorkOSUserUpdated;
use WorkOS\Webhook;

class WebhookController
{
    /** @var array<string, class-string> */
    public const array EVENT_MAP = [
        'user.created' => WorkOSUserCreated::class,
        'user.updated' => WorkOSUserUpdated::class,
        'user.deleted' => WorkOSUserDeleted::class,
        'organization.created' => WorkOSOrganizationCreated::class,
        'organization.updated' => WorkOSOrganizationUpdated::class,
        'organization.deleted' => WorkOSOrganizationDeleted::class,
        'organization_membership.created' => WorkOSMembershipCreated::class,
        'organization_membership.updated' => WorkOSMembershipUpdated::class,
        'organization_membership.deleted' => WorkOSMembershipDeleted::class,
        'session.created' => WorkOSSessionCreated::class,
        'authentication.email_verification_succeeded' => WorkOSSessionCreated::class,
        'authentication.magic_auth_succeeded' => WorkOSSessionCreated::class,
        'authentication.mfa_succeeded' => WorkOSSessionCreated::class,
        'authentication.oauth_succeeded' => WorkOSSessionCreated::class,
        'authentication.password_succeeded' => WorkOSSessionCreated::class,
        'authentication.sso_succeeded' => WorkOSSessionCreated::class,
        'user.session_revoked' => WorkOSSessionRevoked::class,
    ];

    public function __construct(
        private readonly Webhook $webhook,
    ) {}

    public function handle(Request $request): Response
    {
        $payload = $request->getContent();
        $signature = $request->header('WorkOS-Signature', '');
        /** @var string $secret */
        $secret = config('workos.webhook_secret', '');

        if (empty($secret)) {
            return response('Webhook secret not configured', 500);
        }

        try {
            $result = $this->webhook->constructEvent(
                $signature,
                $payload,
                $secret,
                180 // 3 minute tolerance
            );

            if ($result !== 'pass' && ! is_object($result)) {
                return response('Invalid signature', 400);
            }

            /** @var array{event: string, data: array<string, mixed>} $event */
            $event = json_decode($payload, true);
        } catch (\Exception $e) {
            report($e);

            return response('Invalid signature', 400);
        }

        $eventType = $event['event'];
        $eventData = $event['data'];

        event(new WebhookReceived($eventType, $eventData));

        $eventClass = self::EVENT_MAP[$eventType] ?? null;
        if ($eventClass !== null) {
            event(new $eventClass($eventData));
        }

        return response('OK', 200);
    }
}
