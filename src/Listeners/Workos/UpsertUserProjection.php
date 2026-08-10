<?php

declare(strict_types=1);

namespace Authkit\Authkit\Listeners\Workos;

use Authkit\Authkit\Events\Workos\UserCreated;
use Authkit\Authkit\Events\Workos\UserUpdated;
use Authkit\Authkit\Listeners\Workos\Concerns\ResolvesProjectionModels;
use Illuminate\Support\Facades\Log;

/**
 * Idempotent by construction: keyed on the resource's own WorkOS id
 * (resourceId(), never $event->id — that one differs on every delivery), so a
 * replayed batch or a webhook/poller double-delivery rewrites the same row
 * rather than creating a second one.
 */
final class UpsertUserProjection
{
    use ResolvesProjectionModels;

    public function handle(UserCreated|UserUpdated $event): void
    {
        $model = $this->userProjectionModel();
        $workosId = $event->resourceId();

        $email = $event->payload['email'] ?? null;
        $email = is_string($email) && $email !== '' ? $email : null;

        $user = $model::query()->firstWhere('workos_id', $workosId);

        // Link-by-email mirrors HasWorkosUser::findOrCreateForWorkosUser,
        // including its security gate: only a VERIFIED address may claim an
        // existing local account, otherwise registering victim@corp.com in
        // WorkOS would hand over the victim's pre-WorkOS local account.
        if ($user === null && $email !== null && ($event->payload['email_verified'] ?? null) === true) {
            $user = $model::query()->firstWhere('email', $email);
        }

        if ($user === null && $email !== null && $model::query()->where('email', $email)->exists()) {
            // An UNVERIFIED collision cannot be linked (security gate above)
            // and cannot be created either (unique email column). Unlike the
            // login flow — which throws to the human who can resolve it — a
            // throwing poller listener would replay the same batch forever, so
            // this skips loudly instead of poisoning the pipeline.
            Log::warning('authkit: skipped user projection upsert — unverified email collides with an existing local account', [
                'workos_user_id' => $workosId,
                'event_id' => $event->id,
            ]);

            return;
        }

        if ($user === null) {
            $user = $model::query()->newModelInstance();
        }

        // forceFill: trusted data straight from WorkOS must land regardless of
        // how the consumer's model declares $fillable (HasWorkosUser precedent).
        $attributes = ['workos_id' => $workosId];

        if ($email !== null) {
            $attributes['email'] = $email;
        }

        if ($email !== null && $user->exists
            && $model::query()->where('email', $email)->whereKeyNot($user->getKey())->exists()) {
            // Update-side collision: a user.updated whose new email is already
            // held by a DIFFERENT local row would hit the unique email column
            // on every at-least-once replay — a deterministic, permanent
            // poller stall. Skip the email write (still refreshing the rest)
            // and log, mirroring the create-side collision handling above.
            Log::warning('authkit: skipped user projection email update — new email collides with another local account', [
                'workos_user_id' => $workosId,
                'event_id' => $event->id,
            ]);

            unset($attributes['email']);
        }

        $name = $this->displayName($event->payload);

        if ($name !== null) {
            $attributes['name'] = $name;
        } elseif (! $user->exists) {
            // A brand-new row must satisfy a NOT NULL name column even when the
            // WorkOS user has no name at all — same empty-string floor
            // HasWorkosUser::findOrCreateForWorkosUser writes at creation. An
            // EXISTING row's name is never clobbered by a name-less payload.
            $attributes['name'] = '';
        }

        $user->forceFill($attributes)->save();
    }

    /** @param array<string, mixed> $payload */
    private function displayName(array $payload): ?string
    {
        $name = $payload['name'] ?? null;

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $first = $payload['first_name'] ?? null;
        $last = $payload['last_name'] ?? null;

        $joined = trim((is_string($first) ? $first : '').' '.(is_string($last) ? $last : ''));

        return $joined === '' ? null : $joined;
    }
}
