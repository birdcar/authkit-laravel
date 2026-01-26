<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        $organizations = $this->getUserOrganizations($user);
        if ($organizations === null) {
            return;
        }

        $organization = $this->findOrganization($event->organizationId());
        if ($organization !== null) {
            $organizations->detach($organization->getKey());
        }
    }

    private function syncMembership(string $userId, string $organizationId, ?string $role): void
    {
        $user = $this->findUser($userId);
        $organizations = $this->getUserOrganizations($user);
        if ($organizations === null) {
            return;
        }

        $organization = $this->findOrganization($organizationId);
        if ($organization !== null) {
            $organizations->syncWithoutDetaching([
                $organization->getKey() => ['role' => $role],
            ]);
        }
    }

    /**
     * Find a user by their WorkOS ID.
     */
    private function findUser(string $userId): ?Model
    {
        /** @var class-string<Model>|null $userModel */
        $userModel = config('workos.user_model');

        if ($userModel === null || ! method_exists($userModel, 'findByWorkOSId')) {
            return null;
        }

        /** @var Model|null */
        return $userModel::findByWorkOSId($userId);
    }

    /**
     * Get the organizations relationship from a user model.
     *
     * @return BelongsToMany<Model, Model>|null
     */
    private function getUserOrganizations(?Model $user): ?BelongsToMany
    {
        if ($user === null || ! method_exists($user, 'organizations')) {
            return null;
        }

        /** @var BelongsToMany<Model, Model> */
        return $user->organizations();
    }

    /**
     * Find an organization by its WorkOS ID.
     */
    private function findOrganization(string $organizationId): ?Model
    {
        /** @var class-string<Model>|null $organizationModel */
        $organizationModel = config('workos.organization_model');

        if ($organizationModel === null || ! method_exists($organizationModel, 'where')) {
            return null;
        }

        /** @var Model|null */
        return $organizationModel::where('workos_id', $organizationId)->first();
    }
}
