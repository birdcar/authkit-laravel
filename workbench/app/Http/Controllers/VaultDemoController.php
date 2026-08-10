<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Authkit\Authkit\Facades\Vault;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\VaultDemoRecord;

/**
 * Vault demo: one endpoint round-trips all three sub-mechanisms — the
 * Vaulted Eloquent cast (via the VaultDemoRecord fixture model), the vault
 * filesystem driver (the vault-demo disk wraps local), and the Vault KV
 * facade — because the contract's success criterion for Vault is phrased as
 * a single combined assertion.
 *
 * Requires real WorkOS credentials (or a Vault-capable backend): emulate
 * 0.6.0 has no Vault routes, so this route is for live-credential trials,
 * not the emulate-backed suites (Vault's own Pest suite is MockHandler-backed).
 */
final class VaultDemoController extends Controller
{
    public function roundTrip(): JsonResponse
    {
        $record = VaultDemoRecord::query()->create(['secret' => 'demo-secret']);
        $castRoundTrip = $record->refresh()->getAttribute('secret') === 'demo-secret';

        Storage::disk('vault-demo')->put('demo.txt', 'demo-file-contents');
        $fileRoundTrip = Storage::disk('vault-demo')->get('demo.txt') === 'demo-file-contents';

        Vault::set(['app' => 'workbench-demo'], 'workbench-demo-key', 'demo-kv-value');
        $kvRoundTrip = Vault::get('workbench-demo-key')->value === 'demo-kv-value';

        return response()->json([
            'cast_round_trip' => $castRoundTrip,
            'file_round_trip' => $fileRoundTrip,
            'kv_round_trip' => $kvRoundTrip,
        ]);
    }
}
