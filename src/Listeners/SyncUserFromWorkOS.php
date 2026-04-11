<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Listeners;

use WorkOS\AuthKit\Events\Webhooks\WorkOSUserCreated;
use WorkOS\AuthKit\Events\Webhooks\WorkOSUserUpdated;

class SyncUserFromWebhook
{
    public function handle(WorkOSUserUpdated|WorkOSUserCreated $event): void
    {
        /** @var class-string $userModel */
        $userModel = config('workos.user_model');

        if (! method_exists($userModel, 'findByWorkOSId')) {
            return;
        }

        $user = $userModel::findByWorkOSId($event->userId());

        if ($user === null) {
            return;
        }

        $firstName = $event->firstName() ?? '';
        $lastName = $event->lastName() ?? '';
        $name = trim("{$firstName} {$lastName}");

        $user->update([
            'email' => $event->email(),
            'name' => $name !== '' ? $name : null,
        ]);
    }
}
