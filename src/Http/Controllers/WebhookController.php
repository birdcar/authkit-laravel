<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use WorkOS\AuthKit\Events\WorkOSEventReceived;
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncActivated;
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncDeleted;
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncGroupCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncGroupDeleted;
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncGroupUpdated;
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncGroupUserAdded;
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncGroupUserRemoved;
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncUserCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncUserDeleted;
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncUserUpdated;
use WorkOS\AuthKit\Events\Sync\WorkOSMembershipCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationDomainCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationDomainDeleted;
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationDomainUpdated;
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationDomainVerificationFailed;
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationDomainVerified;
use WorkOS\AuthKit\Events\Sync\WorkOSMembershipDeleted;
use WorkOS\AuthKit\Events\Sync\WorkOSMembershipUpdated;
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationDeleted;
use WorkOS\AuthKit\Events\Sync\WorkOSOrganizationUpdated;
use WorkOS\AuthKit\Events\Sync\WorkOSSessionCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSSessionRevoked;
use WorkOS\AuthKit\Events\Sync\WorkOSUserCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSUserDeleted;
use WorkOS\AuthKit\Events\Sync\WorkOSUserUpdated;
use WorkOS\AuthKit\Support\EventRouting;
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
        'authentication.passkey_succeeded' => WorkOSSessionCreated::class,
        'authentication.sso_succeeded' => WorkOSSessionCreated::class,
        'session.revoked' => WorkOSSessionRevoked::class,
        'dsync.activated' => WorkOSDsyncActivated::class,
        'dsync.deleted' => WorkOSDsyncDeleted::class,
        'dsync.user.created' => WorkOSDsyncUserCreated::class,
        'dsync.user.updated' => WorkOSDsyncUserUpdated::class,
        'dsync.user.deleted' => WorkOSDsyncUserDeleted::class,
        'dsync.group.created' => WorkOSDsyncGroupCreated::class,
        'dsync.group.updated' => WorkOSDsyncGroupUpdated::class,
        'dsync.group.deleted' => WorkOSDsyncGroupDeleted::class,
        'dsync.group.user_added' => WorkOSDsyncGroupUserAdded::class,
        'dsync.group.user_removed' => WorkOSDsyncGroupUserRemoved::class,
        'organization_domain.created' => WorkOSOrganizationDomainCreated::class,
        'organization_domain.updated' => WorkOSOrganizationDomainUpdated::class,
        'organization_domain.deleted' => WorkOSOrganizationDomainDeleted::class,
        'organization_domain.verified' => WorkOSOrganizationDomainVerified::class,
        'organization_domain.verification_failed' => WorkOSOrganizationDomainVerificationFailed::class,
    ];

    public function __construct(
        private readonly Webhook $webhook,
        private readonly EventRouting $routing,
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

        if (empty($signature)) {
            return response('Invalid signature', 400);
        }

        $result = $this->webhook->constructEvent(
            $signature,
            $payload,
            $secret,
            180,
        );

        if (is_string($result)) {
            return response('Invalid signature', 400);
        }

        /** @var array{event: string, data: array<string, mixed>} $event */
        $event = json_decode($payload, true);

        $eventType = $event['event'];
        $eventData = $event['data'];

        event(new WorkOSEventReceived($eventType, $eventData));

        $eventClass = self::EVENT_MAP[$eventType] ?? null;
        if ($eventClass !== null && $this->routing->shouldProcessVia($eventType, 'webhooks')) {
            event(new $eventClass($eventData));
        }

        return response('OK', 200);
    }
}
