<?php

declare(strict_types=1);

namespace Authkit\Authkit\AuditLogs\Support;

use Authkit\Authkit\AuditLogs\Exceptions\MissingOrganizationContextException;
use Authkit\Authkit\Contracts\HasAccessTokenClaims;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves the actor and organization for an audit event from the current
 * workos-guard session. The organization comes from the session's org_id
 * claim, not the local org projection — audit events need the raw WorkOS
 * organization id whether or not the app configured a local org model
 * (spec-phase-6 Open Item 1 reconciliation: Authkit::currentOrganizationId()
 * never landed; the claim read lives here instead).
 */
final class AuditActorResolver
{
    /**
     * @param  array{id?: string, type?: string, name?: string}|null  $actorOverride
     */
    public function resolve(
        ?string $organizationId = null,
        ?array $actorOverride = null,
    ): ResolvedAuditContext {
        $guard = Auth::guard('workos');
        $user = $guard->user();

        $workosId = data_get($user, 'workos_id');
        $name = data_get($user, 'name');
        $email = data_get($user, 'email');

        $actorId = $actorOverride['id']
            ?? (is_string($workosId) && $workosId !== '' ? $workosId : null)
            ?? 'system';
        $actorType = $actorOverride['type'] ?? ($user !== null ? 'user' : 'system');
        $actorName = $actorOverride['name']
            ?? (is_string($name) && $name !== '' ? $name : null)
            ?? (is_string($email) && $email !== '' ? $email : null);

        $resolvedOrganizationId = $organizationId ?? $this->organizationIdFromClaims($guard);

        if ($resolvedOrganizationId === null) {
            throw MissingOrganizationContextException::forAuditLog();
        }

        $request = app()->bound('request') ? app('request') : null;
        $request = $request instanceof Request ? $request : null;

        return new ResolvedAuditContext(
            actorId: $actorId,
            actorType: $actorType,
            actorName: $actorName,
            organizationId: $resolvedOrganizationId,
            location: $request?->ip() ?? 'unknown',
            userAgent: $request?->userAgent(),
        );
    }

    private function organizationIdFromClaims(object $guard): ?string
    {
        if (! $guard instanceof HasAccessTokenClaims) {
            return null;
        }

        $claims = $guard->accessTokenClaims();

        if (! is_array($claims)) {
            return null;
        }

        $organizationId = $claims['org_id'] ?? null;

        return is_string($organizationId) && $organizationId !== '' ? $organizationId : null;
    }
}
