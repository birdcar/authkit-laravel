<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Audit;

use Illuminate\Contracts\Auth\Authenticatable;
use WorkOS\AuditLogs;
use WorkOS\AuthKit\Audit\Contracts\Auditable;
use WorkOS\AuthKit\Auth\SessionManager;

class AuditLogger
{
    public function __construct(
        private readonly AuditLogs $auditLogs,
        private readonly SessionManager $sessionManager,
    ) {}

    /**
     * @param  array<int, Auditable|array{type?: string, id?: string|int, name?: string|null, metadata?: array<string, mixed>|null}>  $targets
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        string $action,
        array $targets = [],
        ?string $actorId = null,
        array $metadata = [],
    ): void {
        if (! config('workos.features.audit_logs', false)) {
            return;
        }

        $orgId = $this->sessionManager->getOrganizationId();
        if ($orgId === null) {
            return;
        }

        /** @var \Illuminate\Contracts\Auth\Guard $guard */
        $guard = auth();
        /** @var Authenticatable|null $user */
        $user = $guard->user();

        /** @var array<string, mixed> $event */
        $event = [
            'action' => [
                'type' => $action,
                'name' => $this->humanize($action),
            ],
            'actor' => [
                'type' => 'user',
                'id' => $actorId ?? $this->getActorId($user),
                'name' => $this->getUserName($user),
            ],
            'targets' => $this->normalizeTargets($targets),
            'context' => [
                'location' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ],
            'metadata' => $metadata,
            'occurred_at' => now()->toIso8601String(),
        ];

        try {
            $this->auditLogs->createEvent($orgId, $event);
        } catch (\Exception $e) {
            report($e);
        }
    }

    /**
     * @param  array<int, Auditable|array{type?: string, id?: string|int, name?: string|null, metadata?: array<string, mixed>|null}>  $targets
     * @return array<int, array{type: string, id: string, name: ?string, metadata?: array<string, mixed>|null}>
     */
    private function normalizeTargets(array $targets): array
    {
        return array_map(function ($target) {
            if ($target instanceof Auditable) {
                return $target->toAuditTarget();
            }

            return [
                'type' => $target['type'] ?? 'resource',
                'id' => (string) ($target['id'] ?? ''),
                'name' => $target['name'] ?? null,
                'metadata' => $target['metadata'] ?? null,
            ];
        }, $targets);
    }

    private function humanize(string $action): string
    {
        return ucfirst(str_replace(['.', '_'], ' ', $action));
    }

    private function getActorId(?Authenticatable $user): ?string
    {
        if ($user === null) {
            return null;
        }

        if (method_exists($user, 'getWorkOSId')) {
            /** @var string|null */
            return $user->getWorkOSId();
        }

        return null;
    }

    private function getUserName(?Authenticatable $user): ?string
    {
        if ($user === null) {
            return null;
        }

        /** @var string|null */
        return $user->name ?? null;
    }
}
