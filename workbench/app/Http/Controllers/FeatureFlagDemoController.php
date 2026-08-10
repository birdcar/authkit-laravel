<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Laravel\Pennant\Feature;
use Workbench\App\Models\User;

/**
 * Feature Flags demo, HTTP context: inside an authenticated workos-guard
 * session whose subject matches the scope, the workos Pennant driver answers
 * from the JWT's feature_flags claim with zero HTTP. The console command
 * demo:feature-flags proves the complementary no-session API-fallback path.
 */
final class FeatureFlagDemoController extends Controller
{
    public function check(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json([
            'flag' => 'demo-flag',
            // Feature::store('workos') pins the package driver explicitly so
            // the demo works regardless of the app's pennant.default store.
            'active' => Feature::store('workos')->for($user)->active('demo-flag'),
        ]);
    }
}
