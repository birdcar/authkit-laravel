<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Todo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $organization = $request->attributes->get('current_organization');

        $todoQuery = Todo::where('user_id', $user->id);
        if ($organization) {
            $todoQuery->where('organization_id', $organization->id);
        }

        return view('dashboard', [
            'todoCount' => $todoQuery->count(),
            'completedCount' => (clone $todoQuery)->where('completed', true)->count(),
            'currentOrganization' => $organization,
            'memberCount' => $organization?->users()->count() ?? 0,
        ]);
    }
}
