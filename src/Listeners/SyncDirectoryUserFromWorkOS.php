<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Listeners;

use WorkOS\AuthKit\Events\Sync\WorkOSDsyncUserCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncUserDeleted;
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncUserUpdated;

class SyncDirectoryUserFromWorkOS
{
    public function handleCreatedOrUpdated(WorkOSDsyncUserUpdated|WorkOSDsyncUserCreated $event): void
    {
        /** @var class-string|null $model */
        $model = config('workos.dsync.user_model');

        if ($model === null || ! method_exists($model, 'findByWorkOSId')) {
            return;
        }

        $user = $model::findByWorkOSId($event->directoryUserId());

        if ($user === null) {
            return;
        }

        $firstName = $event->firstName() ?? '';
        $lastName = $event->lastName() ?? '';
        $name = trim("{$firstName} {$lastName}");

        $user->update([
            'email' => $event->email(),
            'name' => $name !== '' ? $name : null,
            'workos_state' => $event->state(),
        ]);
    }

    public function handleDeleted(WorkOSDsyncUserDeleted $event): void
    {
        /** @var class-string|null $model */
        $model = config('workos.dsync.user_model');

        if ($model === null || ! method_exists($model, 'findByWorkOSId')) {
            return;
        }

        $user = $model::findByWorkOSId($event->directoryUserId());
        $user?->delete();
    }
}
