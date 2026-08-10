<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Authkit\Authkit\Facades\Authkit;
use Authkit\Authkit\Pipes\Data\ConnectedAccountData;
use Authkit\Authkit\Pipes\Data\ProviderConfigurationData;
use Authkit\Authkit\Pipes\Exceptions\PipesAccountNotConnectedException;
use Authkit\Authkit\Pipes\Exceptions\PipesReauthorizationRequiredException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workbench\App\Models\User;

/**
 * Pipes playground — exercises every PipesManager entry point plus the two
 * HasWorkosUser conveniences, with zero WorkOS SDK references (the package
 * boundary the workbench grep criterion enforces).
 */
final class PipesController extends Controller
{
    /**
     * The authenticated user's connected accounts, via the trait
     * convenience.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json(
            $user->connectedAccounts()->map(fn (ConnectedAccountData $account): array => [
                'provider' => $account->providerSlug,
                'state' => $account->state->value,
                'scopes' => $account->scopes,
            ])->all(),
        );
    }

    /**
     * Fetch an auto-refreshed token via $user->pipe(), demonstrating both
     * named failure modes: never-connected returns 404, and reauthorization
     * drift redirects straight to the URL the exception carries. The raw
     * token value is deliberately not echoed back.
     */
    public function token(Request $request, string $provider): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        try {
            $token = $user->pipe($provider);
        } catch (PipesAccountNotConnectedException $exception) {
            abort(404, $exception->getMessage());
        } catch (PipesReauthorizationRequiredException $exception) {
            return redirect($exception->reauthorizationUrl);
        }

        return response()->json([
            'provider' => $provider,
            'scopes' => $token->scopes,
            'expires_at' => $token->expiresAt?->format(DATE_ATOM),
        ]);
    }

    /**
     * The org provider-config passthrough, read side. The organization
     * comes from the query string, matching the depth-extensions playground
     * convention.
     */
    public function providers(Request $request): JsonResponse
    {
        $organizationId = $request->query('organization_id');
        abort_unless(
            is_string($organizationId) && $organizationId !== '',
            422,
            'Pass ?organization_id=org_... to list provider configurations.',
        );

        return response()->json(
            Authkit::pipes()->providerConfig($organizationId)->map(fn (ProviderConfigurationData $config): array => [
                'provider' => $config->providerSlug,
                'enabled' => $config->enabled,
                'scopes' => $config->scopes,
                'organization_credentials' => $config->hasOrganizationCredentials,
            ])->all(),
        );
    }

    /**
     * The org provider-config passthrough, write side: enable/disable a
     * provider for one organization.
     */
    public function configureProvider(Request $request, string $provider): JsonResponse
    {
        $organizationId = $request->input('organization_id');
        abort_unless(
            is_string($organizationId) && $organizationId !== '',
            422,
            'Pass organization_id=org_... to configure a provider.',
        );

        $configuration = Authkit::pipes()->configureProvider(
            organizationId: $organizationId,
            providerSlug: $provider,
            enabled: $request->has('enabled') ? $request->boolean('enabled') : null,
        );

        return response()->json([
            'provider' => $configuration->providerSlug,
            'enabled' => $configuration->enabled,
        ]);
    }
}
