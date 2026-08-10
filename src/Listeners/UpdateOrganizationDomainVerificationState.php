<?php

declare(strict_types=1);

namespace Authkit\Authkit\Listeners;

use Authkit\Authkit\Events\Workos\OrganizationDomainVerificationFailed;
use Authkit\Authkit\Events\Workos\OrganizationDomainVerified;
use Authkit\Authkit\Models\WorkosOrganizationDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Owns the verification outcome for the domains projection: verified clears
 * the (now-spent) verification token fields and stamps state=verified;
 * verification_failed stamps state=failed regardless of payload shape (the
 * real payload carries only `reason`, never a top-level state).
 *
 * Deliberately never creates a row: an event for an unknown workos_id (out of
 * order, replay, or locally deleted) warns and no-ops — the row is reconciled
 * later by the created/updated projection listeners, which own row existence.
 */
final class UpdateOrganizationDomainVerificationState
{
    public function handleVerified(OrganizationDomainVerified $event): void
    {
        $this->applyState($event->resourceId(), 'verified', [
            'verification_prefix' => null,
            'verification_token' => null,
        ]);
    }

    public function handleVerificationFailed(OrganizationDomainVerificationFailed $event): void
    {
        $this->applyState($event->resourceId(), 'failed', []);
    }

    /**
     * @param  array<string, string|null>  $extra
     */
    private function applyState(string $workosDomainId, string $state, array $extra): void
    {
        DB::transaction(function () use ($workosDomainId, $state, $extra): void {
            // lockForUpdate: a concurrent local delete either wins (vanished
            // row → warn + no-op, same as never-projected) or waits for this
            // update to land first — never a half-applied state.
            $row = WorkosOrganizationDomain::query()
                ->where('workos_id', $workosDomainId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                Log::warning('authkit: domain-verification event for unknown domain projection row', [
                    'workos_id' => $workosDomainId,
                    'state' => $state,
                ]);

                return;
            }

            $row->forceFill(['state' => $state, ...$extra])->save();
        });
    }
}
