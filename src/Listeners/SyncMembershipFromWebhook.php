<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Listeners;

use WorkOS\AuthKit\Events\Webhooks\WorkOSMembershipCreated;
use WorkOS\AuthKit\Events\Webhooks\WorkOSMembershipDeleted;
use WorkOS\AuthKit\Events\Webhooks\WorkOSMembershipUpdated;

class SyncMembershipFromWebhook
{
    public function handleCreated(WorkOSMembershipCreated $event): void
    {
        $this->syncMembership($event->userId(), $event->organizationId(), $event->role());
    }

    public function handleUpdated(WorkOSMembershipUpdated $event): void
    {
        $this->syncMembership($event->userId(), $event->organizationId(), $event->role());
    }

    public function handleDeleted(WorkOSMembershipDeleted $event): void
    {
        /** @var class-string $userModel */
        $userModel = config('workos.user_model');

        if (! method_exists($userModel, 'findByWorkOSId')) {
            return;
        }

        /** @var object|null $user */
        $user = $userModel::findByWorkOSId($event->userId());

        if ($user === null || ! method_exists($user, 'organizations')) {
            return;
        }

        /** @var class-string $organizationModel */
        $organizationModel = config('workos.organization_model');

        if (! method_exists($organizationModel, 'where')) {
            return;
        }

        $organization = $organizationModel::where('workos_id', $event->organizationId())->first();

        if ($organization !== null) {
            $user->organizations()->detach($organization->id);
        }
    }

    private function syncMembership(string $userId, string $organizationId, ?string $role): void
    {
        /** @var class-string $userModel */
        $userModel = config('workos.user_model');

        if (! method_exists($userModel, 'findByWorkOSId')) {
            return;
        }

        /** @var object|null $user */
        $user = $userModel::findByWorkOSId($userId);

        if ($user === null || ! method_exists($user, 'organizations')) {
            return;
        }

        /** @var class-string $organizationModel */
        $organizationModel = config('workos.organization_model');

        if (! method_exists($organizationModel, 'where')) {
            return;
        }

        $organization = $organizationModel::where('workos_id', $organizationId)->first();

        if ($organization !== null) {
            $user->organizations()->syncWithoutDetaching([
                $organization->id => ['role' => $role],
            ]);
        }
    }
}
