<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Listeners\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use WorkOS\AuthKit\Events\WorkOSEventReceived;
use WorkOS\AuthKit\Facades\WorkOS;

trait HandlesWorkOSEvents
{
    /**
     * Resolve the user model from an event's WorkOS user ID.
     *
     * Tries 'user_id' first (membership/session events), then 'id' (user events).
     */
    protected function resolveUser(object $event): ?Authenticatable
    {
        /** @var string|null $userId */
        $userId = $event->get('user_id') ?? $event->get('id');

        if (! $userId) {
            return null;
        }

        /** @var class-string<Model&Authenticatable> $model */
        $model = config('workos.user_model');

        /** @var (Model&Authenticatable)|null */
        return $model::where('workos_id', $userId)->first();
    }

    /**
     * Resolve the organization model from an event's WorkOS org ID.
     *
     * Tries 'organization_id' first (membership events), then 'id' (organization events).
     */
    protected function resolveOrganization(object $event): ?Model
    {
        /** @var string|null $orgId */
        $orgId = $event->get('organization_id') ?? $event->get('id');

        if (! $orgId) {
            return null;
        }

        /** @var class-string<Model> $model */
        $model = config('workos.organization_model');

        return $model::where('workos_id', $orgId)->first();
    }

    /**
     * Log an audit event to WorkOS Audit Logs.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function audit(string $action, array $metadata = []): void
    {
        WorkOS::audit($action, metadata: $metadata);
    }

    /**
     * Log a structured message with event context.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logEvent(string $message, object $event, array $context = []): void
    {
        Log::info($message, array_merge([
            'workos_event' => $event instanceof WorkOSEventReceived
                ? $event->event
                : class_basename($event),
        ], $context));
    }

    /**
     * Execute listener logic within a database transaction.
     */
    protected function withinTransaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }
}
