<?php

declare(strict_types=1);

namespace Authkit\Authkit\Auth;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use WorkOS\SessionManager;

/**
 * Refresh tokens rotate on every use, so two concurrent requests from the same
 * browser both calling `SessionManager::refresh()` means the loser presents an
 * already-invalidated token. A cache lock keyed on session ID serializes the real
 * call; a short-TTL shared result lets the losers reuse the winner's cookie.
 */
final readonly class SessionRefresher
{
    public function __construct(
        private SessionManager $sessionManager,
        private int $lockTtlSeconds,
        private int $lockWaitSeconds,
    ) {}

    public function refresh(string $sealedCookie, string $sessionId, string $cookiePassword, string $clientId): RefreshOutcome
    {
        $resultKey = "authkit:refresh-result:{$sessionId}";

        // Checked before touching the lock at all, so only the first concurrent
        // request per refresh cycle ever contends.
        $cached = Cache::get($resultKey);

        if (is_string($cached)) {
            return new RefreshOutcome(RefreshStatus::Refreshed, $cached);
        }

        // Cache::lock() throws BadMethodCallException on a store that is not a
        // LockProvider (`null`, and any custom driver that skipped it). A degraded
        // cache configuration should cost coordination, not turn every near-expiry
        // request into a 500.
        if (! Cache::getStore() instanceof LockProvider) {
            return new RefreshOutcome(RefreshStatus::ProceedWithExisting);
        }

        $lock = Cache::lock("authkit:refresh-lock:{$sessionId}", $this->lockTtlSeconds);

        try {
            $lock->block($this->lockWaitSeconds);
        } catch (LockTimeoutException) {
            // Someone else is refreshing and took longer than we will wait. The
            // caller decides ProceedWithExisting vs HardExpired from its own
            // remaining exp budget. Note block() throws rather than returning
            // false, so this must be a catch — a truthiness check never fires.
            return new RefreshOutcome(RefreshStatus::ProceedWithExisting);
        }

        try {
            $cached = Cache::get($resultKey);

            if (is_string($cached)) {
                return new RefreshOutcome(RefreshStatus::Refreshed, $cached);
            }

            $result = $this->sessionManager->refresh($sealedCookie, $cookiePassword, $clientId);

            if (($result['authenticated'] ?? false) !== true) {
                return new RefreshOutcome(RefreshStatus::HardExpired);
            }

            $sealed = $result['sealed_session'] ?? null;

            if (! is_string($sealed) || $sealed === '') {
                return new RefreshOutcome(RefreshStatus::HardExpired);
            }

            Cache::put($resultKey, $sealed, now()->addSeconds($this->lockTtlSeconds * 2));

            return new RefreshOutcome(RefreshStatus::Refreshed, $sealed);
        } finally {
            $lock->release();
        }
    }
}
