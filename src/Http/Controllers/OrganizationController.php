<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use WorkOS\AuthKit\Events\InvitationRevoked;
use WorkOS\AuthKit\Events\InvitationSent;
use WorkOS\AuthKit\Facades\WorkOS;

class OrganizationController extends Controller
{
    public function switch(Request $request): RedirectResponse
    {
        $request->validate([
            'organization_id' => 'required|string',
        ]);

        $user = $request->user();
        /** @var string $orgId */
        $orgId = $request->input('organization_id');

        if (! $user || ! method_exists($user, 'switchOrganization')) {
            return back()->withErrors(['organization' => 'User does not support organization switching.']);
        }

        /** @var callable $switchOrganization */
        $switchOrganization = [$user, 'switchOrganization'];
        if (! $switchOrganization($orgId)) {
            return back()->withErrors(['organization' => 'You do not belong to this organization.']);
        }

        return redirect()->intended('/');
    }

    public function invite(Request $request, string $organizationId): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
            'role' => 'nullable|string',
        ]);

        /** @var \WorkOS\UserManagement $userManagement */
        $userManagement = WorkOS::userManagement();

        /** @var string $email */
        $email = $request->input('email');
        /** @var string|null $roleSlug */
        $roleSlug = $request->input('role');

        $invitation = $userManagement->sendInvitation(
            email: $email,
            organizationId: $organizationId,
            roleSlug: $roleSlug,
        );

        event(new InvitationSent($organizationId, $email, $invitation));

        return back()->with('success', 'Invitation sent.');
    }

    public function revokeInvitation(Request $request, string $organizationId, string $invitationId): RedirectResponse
    {
        /** @var \WorkOS\UserManagement $userManagement */
        $userManagement = WorkOS::userManagement();

        // Fetch invitation to verify it belongs to the organization
        $invitation = $userManagement->getInvitation($invitationId);

        /** @phpstan-ignore property.notFound (organizationId accessed via magic __get) */
        if ($invitation->organizationId !== $organizationId) {
            return back()->withErrors(['invitation' => 'Invitation does not belong to this organization.']);
        }

        $userManagement->revokeInvitation($invitationId);

        event(new InvitationRevoked($organizationId, $invitationId));

        return back()->with('success', 'Invitation revoked.');
    }
}
