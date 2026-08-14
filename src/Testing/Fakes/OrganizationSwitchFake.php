<?php

declare(strict_types=1);

namespace Authkit\Authkit\Testing\Fakes;

use Authkit\Authkit\Contracts\HasAccessTokenClaims;
use Authkit\Authkit\Models\WorkosMembership;
use Authkit\Authkit\Organizations\OrganizationSwitcher;
use Authkit\Authkit\Organizations\OrganizationSwitchResult;
use Authkit\Authkit\Testing\FakesWorkosSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;

/**
 * An in-memory {@see OrganizationSwitcher}. Where the real switcher refreshes
 * the sealed session (deferring the new claims to the next request), the fake
 * collapses the redirect: on a successful switch it re-installs the current
 * fake session with the target org_id — the state the next request would see
 * — so `Authkit::currentOrganization()` reflects the switch immediately.
 *
 * Fidelity rules, mirroring WorkOS's own refusal semantics:
 * - no fake session installed (`Authkit::actingAs` not called) → NoSession;
 * - the workos_memberships projection carries no active row for the session
 *   user in the target org → Refused (WorkOS refuses to scope a session to
 *   an org the user holds no active membership in);
 * - otherwise → Switched, with `role`/`roles` claims rebuilt from the
 *   projection row. `permissions` cannot be derived locally (role→permission
 *   mapping lives in the WorkOS environment) and are carried over verbatim —
 *   a test needing the target org's permissions calls actingAs() after the
 *   switch.
 *
 * refuse() scripts the next switches to be Refused regardless, for exercising
 * fallback paths.
 */
final class OrganizationSwitchFake extends OrganizationSwitcher
{
    /** @var list<string> organization ids passed to switch(), oldest first */
    private array $switches = [];

    private bool $refusing = false;

    public function __construct()
    {
        // Deliberately no parent call: the fake never touches the WorkOS
        // client — switch() below replaces the only inherited method that would.
    }

    /**
     * Script every subsequent switch() to be Refused — the "WorkOS rejected
     * the refresh" path — until allow() is called.
     */
    public function refuse(): self
    {
        $this->refusing = true;

        return $this;
    }

    public function allow(): self
    {
        $this->refusing = false;

        return $this;
    }

    public function switch(Model|string $organization): OrganizationSwitchResult
    {
        $organizationId = $this->resolveOrganizationId($organization);

        $this->switches[] = $organizationId;

        if ($this->refusing) {
            return OrganizationSwitchResult::Refused;
        }

        $guard = Auth::guard('workos');
        $user = $guard->user();

        if ($user === null) {
            return OrganizationSwitchResult::NoSession;
        }

        $claims = $guard instanceof HasAccessTokenClaims ? ($guard->accessTokenClaims() ?? []) : [];
        $subject = $claims['sub'] ?? null;

        $membership = WorkosMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', is_string($subject) ? $subject : '')
            ->where('status', 'active')
            ->first();

        if ($membership === null) {
            return OrganizationSwitchResult::Refused;
        }

        $claims['org_id'] = $organizationId;

        $role = $membership->getAttribute('role');

        if (is_string($role) && $role !== '') {
            $claims['role'] = $role;
            $claims['roles'] = [$role];
        }

        FakesWorkosSession::actingAs($user, [
            'sub' => is_string($subject) ? $subject : null,
            'claims' => $claims,
        ]);

        return OrganizationSwitchResult::Switched;
    }

    public function assertSwitched(Model|string $organization): void
    {
        $organizationId = $this->resolveOrganizationId($organization);

        Assert::assertContains($organizationId, $this->switches, sprintf(
            'Expected a switch to organization [%s], but none happened. %s',
            $organizationId,
            $this->describeSwitches(),
        ));
    }

    public function assertNothingSwitched(): void
    {
        Assert::assertEmpty($this->switches, sprintf('Expected no organization switches. %s', $this->describeSwitches()));
    }

    private function describeSwitches(): string
    {
        if ($this->switches === []) {
            return 'No switches were requested.';
        }

        return 'Switched to: '.implode(', ', $this->switches).'.';
    }

    private function resolveOrganizationId(Model|string $organization): string
    {
        if (is_string($organization)) {
            return $organization;
        }

        $workosId = $organization->getAttribute('workos_id');

        if (! is_string($workosId) || $workosId === '') {
            throw new InvalidArgumentException(sprintf(
                'The fake cannot read an organization id from [%s]: its workos_id is empty. Sync it '
                .'first (or set one directly in the test).',
                $organization::class,
            ));
        }

        return $workosId;
    }
}
