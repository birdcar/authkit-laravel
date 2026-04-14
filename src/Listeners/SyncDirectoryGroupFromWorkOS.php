<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Listeners;

use WorkOS\AuthKit\Events\Sync\WorkOSDsyncGroupCreated;
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncGroupDeleted;
use WorkOS\AuthKit\Events\Sync\WorkOSDsyncGroupUpdated;

class SyncDirectoryGroupFromWorkOS
{
    public function handleCreatedOrUpdated(WorkOSDsyncGroupUpdated|WorkOSDsyncGroupCreated $event): void
    {
        /** @var class-string|null $model */
        $model = config('workos.dsync.group_model');

        if ($model === null || ! method_exists($model, 'where')) {
            return;
        }

        $group = $model::where('workos_id', $event->directoryGroupId())->first();

        if ($group === null) {
            return;
        }

        $group->update(['name' => $event->name()]);
    }

    public function handleDeleted(WorkOSDsyncGroupDeleted $event): void
    {
        /** @var class-string|null $model */
        $model = config('workos.dsync.group_model');

        if ($model === null || ! method_exists($model, 'where')) {
            return;
        }

        $model::where('workos_id', $event->directoryGroupId())->first()?->delete();
    }
}
