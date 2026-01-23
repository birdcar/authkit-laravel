<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function settings(Request $request): View
    {
        $organization = $request->attributes->get('current_organization');
        $members = $organization?->users()->withPivot('role')->get() ?? collect();

        return view('organizations.settings', [
            'organization' => $organization,
            'members' => $members,
        ]);
    }
}
