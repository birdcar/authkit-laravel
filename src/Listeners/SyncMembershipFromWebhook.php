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
        $user = $this->findUser($event->userId());
        if ($user === null) {
            return;
        }

        $organization = $this->findOrganization($event->organizationId());
        if ($organization !== null) {
            $user->organizations()->detach($organization->id);
        }
    }

    private function syncMembership(string $userId, string $organizationId, ?string $role): void
    {
        $user = $this->findUser($userId);
        if ($user === null) {
            return;
        }

        $organization = $this->findOrganization($organizationId);
        if ($organization !== null) {
            $user->organizations()->syncWithoutDetaching([
                $organization->id => ['role' => $role],
            ]);
        }
    }

    /**
     * Find a user by their WorkOS ID.
     *
     * @return object|null User model with organizations() relationship, or null
     */
    private function findUser(string $userId): ?object
    {
        /** @var class-string $userModel */
        $userModel = config('workos.user_model');

        if (! method_exists($userModel, 'findByWorkOSId')) {
            return null;
        }

        /** @var object|null $user */
        $user = $userModel::findByWorkOSId($userId);

        if ($user === null || ! method_exists($user, 'organizations')) {
            return null;
        }

        return $user;
    }

    /**
     * Find an organization by its WorkOS ID.
     *
     * @return object|null Organization model, or null
     */
    private function findOrganization(string $organizationId): ?object
    {
        /** @var class-string $organizationModel */
        $organizationModel = config('workos.organization_model');

        if (! method_exists($organizationModel, 'where')) {
            return null;
        }

        return $organizationModel::where('workos_id', $organizationId)->first();
    }
}
