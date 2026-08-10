<?php

declare(strict_types=1);

namespace Authkit\Authkit\Http\Middleware;

use Authkit\Authkit\Exceptions\ConfigurationException;
use Authkit\Authkit\Http\Middleware\Exceptions\InvalidMcpTokenException;
use Authkit\Authkit\Support\Jwt\Exceptions\JwksUnavailableException;
use Authkit\Authkit\Support\Jwt\Exceptions\JwtVerificationException;
use Authkit\Authkit\Support\Jwt\JwksVerifier;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `authkit.mcp` alias: RFC 8707 resource-indicator-scoped bearer
 * authentication for MCP resource-server routes.
 *
 * A DIFFERENT auth surface from the `workos` guard: MCP bearer tokens are
 * separately-issued OAuth/M2M access tokens, not the AuthKit sealed session
 * cookie — this middleware never touches the guard, the cookie, or
 * SessionManager::authenticate() (contract decision D2). Verification is
 * local-only (JWKS signature + iss/aud policy): no WorkOS API call ever
 * happens on the request hot path (decision D9), and the explicit `alg`
 * allow-list lives inside the shared JwksVerifier, so a forged `alg: none` or
 * `alg: HS256` token never reaches the iss/aud checks here.
 */
final readonly class AuthenticateMcpToken
{
    public function __construct(private JwksVerifier $jwksVerifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $domain = config('authkit.authkit_domain');
        $resourceIndicator = config('authkit.mcp.resource_indicator');

        if (! is_string($domain) || trim($domain) === '' || ! is_string($resourceIndicator) || trim($resourceIndicator) === '') {
            // Fail fast and loud — a misconfigured MCP guard must not silently
            // reject every request forever (failure mode F10; the well-known
            // route makes the opposite choice, a soft 404, on purpose).
            throw new ConfigurationException(
                'authkit.authkit_domain and authkit.mcp.resource_indicator must both be configured to use the authkit.mcp middleware.',
            );
        }

        $header = $request->header('Authorization');
        $token = $this->extractBearerToken(is_string($header) ? $header : null);

        if ($token === null) {
            return $this->unauthorized(withErrorParam: false); // RFC 6750: no `error=` when no token was attempted
        }

        try {
            $claims = $this->jwksVerifier->verify(
                jwt: $token,
                jwksUrl: "https://{$domain}/oauth2/jwks", // NOT the session JWKS path — different host and path entirely (finding F1)
                cacheKey: "authkit:jwks:mcp:{$domain}",
            );

            if (($claims['iss'] ?? null) !== "https://{$domain}") {
                throw InvalidMcpTokenException::wrongIssuer();
            }

            // The central security property Resource Indicators exist for:
            // without this check, any AuthKit-authenticated bearer token would
            // be valid against every MCP server in the environment (F-wrong-aud).
            if (($claims['aud'] ?? null) !== $resourceIndicator) {
                throw InvalidMcpTokenException::wrongAudience();
            }
        } catch (InvalidMcpTokenException|JwtVerificationException) {
            return $this->unauthorized(withErrorParam: true);
        } catch (JwksUnavailableException) {
            // Our infrastructure failed to verify — not the caller presenting
            // bad credentials. A 401 here would hide a WorkOS outage behind
            // what looks like every client having a bad token (F-jwks-down).
            return response()->json(['error' => 'temporarily_unavailable'], 503);
        }

        $request->attributes->set('authkit.mcp.claims', $claims);

        if ((bool) config('authkit.mcp.resolve_user', false)) {
            $this->resolveLocalUser($request, $claims);
        }

        return $next($request);
    }

    private function extractBearerToken(?string $header): ?string
    {
        if ($header === null) {
            return null;
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Local-user resolution is a local DB lookup by `sub`, never a WorkOS API
     * call (decision D1). Both miss cases are expected, not failures: M2M
     * client-credentials tokens carry no `sub` at all (F-m2m-no-sub), and a
     * user-delegated token may predate the local user's first-login link
     * (F15) — the request proceeds with claims attached either way.
     *
     * @param  array<string, mixed>  $claims
     */
    private function resolveLocalUser(Request $request, array $claims): void
    {
        $sub = $claims['sub'] ?? null;

        if (! is_string($sub) || $sub === '') {
            return;
        }

        $model = config('authkit.mcp.user_model')
            ?? config('auth.providers.workos.model')
            ?? config('auth.providers.users.model');

        if (! is_string($model) || ! class_exists($model) || ! is_a($model, Model::class, true)) {
            return;
        }

        $user = $model::query()->where('workos_id', $sub)->first();

        if ($user !== null) {
            $request->setUserResolver(static fn (): Model => $user);
        }
    }

    private function unauthorized(bool $withErrorParam): Response
    {
        // Hard requirement from WorkOS's AuthKit-for-MCP guide (finding F4):
        // the resource_metadata challenge parameter is what enables MCP client
        // discovery of the authorization server.
        $metadataUrl = url('/.well-known/oauth-protected-resource');

        $challenge = $withErrorParam
            ? sprintf('Bearer error="invalid_token", error_description="The access token is invalid, expired, or was issued for a different resource.", resource_metadata="%s"', $metadataUrl)
            : sprintf('Bearer resource_metadata="%s"', $metadataUrl);

        return response()->json(['error' => 'invalid_token'], 401)->header('WWW-Authenticate', $challenge);
    }
}
