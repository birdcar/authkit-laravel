<?php

declare(strict_types=1);

namespace Authkit\Authkit\Connect;

use Authkit\Authkit\Connect\Exceptions\ConnectException;
use Authkit\Authkit\Contracts\WorkosClientManager;
use Closure;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Collection;
use WorkOS\Exception\WorkOSException;
use WorkOS\PaginatedResponse;
use WorkOS\RequestOptions;
use WorkOS\Resource\ApplicationCredentialsListItem;
use WorkOS\Resource\ApplicationsRegistrationTypes;
use WorkOS\Resource\ConnectApplication as SdkConnectApplication;
use WorkOS\Resource\NewConnectApplicationSecret as SdkNewConnectApplicationSecret;
use WorkOS\Resource\PaginationOrder;
use WorkOS\Resource\RedirectUriInput;

/**
 * The Connect OAuth/M2M application registry, resolved via Authkit::connect().
 *
 * Both boundaries stay SDK-free (spec-phase-10 §6.1): inputs are plain
 * scalars/strings ($order, $registrationTypes, $redirectUris are translated to
 * the SDK's enums/value objects internally, never echoed in signatures), and
 * outputs are package-owned DTOs. Application registry data and client
 * secrets are never persisted locally (contract decision D4).
 *
 * completeOAuth2 (Standalone Connect) is deliberately not wrapped — it
 * belongs to a different flow no MVP scope row requests.
 */
final class ConnectManager
{
    public function __construct(private readonly WorkosClientManager $clients) {}

    /**
     * @param  array<int, string>|null  $scopes
     * @param  array<int, string>|null  $redirectUris  list of plain URI strings, not RedirectUriInput
     */
    public function createOAuthApplication(
        string $name,
        bool $isFirstParty,
        ?string $description = null,
        ?array $scopes = null,
        ?array $redirectUris = null,
        ?bool $usesPkce = null,
        ?string $organizationId = null,
        ?string $idempotencyKey = null,
    ): Data\ConnectApplication {
        $application = $this->call(fn (): SdkConnectApplication => $this->clients->client()->connect()->createOAuthApplication(
            name: $name,
            isFirstParty: $isFirstParty,
            description: $description,
            scopes: $scopes,
            redirectUris: self::toRedirectUriInputs($redirectUris),
            usesPkce: $usesPkce,
            organizationId: $organizationId,
            options: self::toRequestOptions($idempotencyKey),
        ));

        return Data\ConnectApplication::fromSdk($application);
    }

    /**
     * @param  array<int, string>|null  $scopes
     */
    public function createM2MApplication(
        string $name,
        string $organizationId,
        ?string $description = null,
        ?array $scopes = null,
        ?string $idempotencyKey = null,
    ): Data\ConnectApplication {
        // Fail fast before the wire: a blank organizationId (org context not
        // yet resolved, coerced to '') would otherwise surface as a raw WorkOS
        // 400 with no pointer at the real cause (failure mode F11).
        if (trim($organizationId) === '') {
            throw ConnectException::organizationIdRequired();
        }

        $application = $this->call(fn (): SdkConnectApplication => $this->clients->client()->connect()->createM2MApplication(
            name: $name,
            organizationId: $organizationId,
            description: $description,
            scopes: $scopes,
            options: self::toRequestOptions($idempotencyKey),
        ));

        return Data\ConnectApplication::fromSdk($application);
    }

    /**
     * @param  array<int, string>|null  $registrationTypes  array<'dynamic'|'authenticated'> — translated internally
     * @return Collection<int, Data\ConnectApplication>
     */
    public function listApplications(
        ?string $before = null,
        ?string $after = null,
        ?int $limit = null,
        string $order = 'desc',
        ?array $registrationTypes = null,
        ?string $organizationId = null,
    ): Collection {
        $page = $this->call(fn (): PaginatedResponse => $this->clients->client()->connect()->listApplications(
            before: $before,
            after: $after,
            limit: $limit,
            order: PaginationOrder::from($order),
            // Enum VALUES, not the instances the SDK docblock advertises: the
            // SDK pipes this array straight into http_build_query, which only
            // serializes backed enums by value on PHP 8.5+ — on 8.3/8.4 each
            // enum explodes into its public name/value properties and the
            // filter reaches the API as registration_types[0][name]=… garbage.
            // from() still rejects unknown types before any HTTP happens.
            // @phpstan-ignore argument.type (the SDK docblock wants enum instances; honoring it breaks the wire on PHP <8.5)
            registrationTypes: $registrationTypes === null
                ? null
                : array_map(static fn (string $type): string => ApplicationsRegistrationTypes::from($type)->value, $registrationTypes),
            organizationId: $organizationId,
        ));

        return Collection::make($page->data)
            ->whereInstanceOf(SdkConnectApplication::class)
            ->map(static fn (SdkConnectApplication $application): Data\ConnectApplication => Data\ConnectApplication::fromSdk($application))
            ->values();
    }

    public function getApplication(string $id): Data\ConnectApplication
    {
        $application = $this->call(fn (): SdkConnectApplication => $this->clients->client()->connect()->getApplication($id));

        return Data\ConnectApplication::fromSdk($application);
    }

    /**
     * @param  array<int, string>|null  $scopes
     * @param  array<int, string>|null  $redirectUris  list of plain URI strings; OAuth applications only
     */
    public function updateApplication(
        string $id,
        ?string $name = null,
        ?string $description = null,
        ?array $scopes = null,
        ?array $redirectUris = null,
    ): Data\ConnectApplication {
        $application = $this->call(fn (): SdkConnectApplication => $this->clients->client()->connect()->updateApplication(
            id: $id,
            name: $name,
            description: $description,
            scopes: $scopes,
            redirectUris: self::toRedirectUriInputs($redirectUris),
        ));

        return Data\ConnectApplication::fromSdk($application);
    }

    public function deleteApplication(string $id): void
    {
        $this->call(function () use ($id): true {
            $this->clients->client()->connect()->deleteApplication($id);

            return true;
        });
    }

    /**
     * @return Collection<int, Data\ConnectApplicationSecret>
     */
    public function listClientSecrets(string $applicationId): Collection
    {
        $secrets = $this->call(fn (): array => $this->clients->client()->connect()->listApplicationClientSecrets($applicationId));

        return Collection::make($secrets)
            ->whereInstanceOf(ApplicationCredentialsListItem::class)
            ->map(static fn (ApplicationCredentialsListItem $secret): Data\ConnectApplicationSecret => Data\ConnectApplicationSecret::fromSdk($secret))
            ->values();
    }

    public function createClientSecret(string $applicationId): Data\NewConnectApplicationSecret
    {
        $secret = $this->call(fn (): SdkNewConnectApplicationSecret => $this->clients->client()->connect()->createApplicationClientSecret($applicationId));

        return Data\NewConnectApplicationSecret::fromSdk($secret);
    }

    public function deleteClientSecret(string $secretId): void
    {
        $this->call(function () use ($secretId): true {
            $this->clients->client()->connect()->deleteClientSecret($secretId);

            return true;
        });
    }

    /**
     * Creates a new secret FIRST, then deletes $secretIdToRevoke — in that
     * order, never reversed: the new secret must exist before the old one is
     * revoked, or in-flight OAuth token exchanges using the old secret break
     * mid-rotation (failure mode F12). If the delete step then fails, the
     * just-created secret is deliberately NOT rolled back — retrying the whole
     * rotation would mint a third, unnecessary secret. Callers should retry
     * only deleteClientSecret($secretIdToRevoke) on that failure path.
     */
    public function rotateClientSecret(string $applicationId, string $secretIdToRevoke): Data\NewConnectApplicationSecret
    {
        $newSecret = $this->createClientSecret($applicationId);

        $this->deleteClientSecret($secretIdToRevoke);

        return $newSecret;
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $operation
     * @return TReturn
     */
    private function call(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (WorkOSException|GuzzleException $e) {
            throw ConnectException::operationFailed($e);
        }
    }

    /**
     * @param  array<int, string>|null  $redirectUris
     * @return array<int, RedirectUriInput>|null
     */
    private static function toRedirectUriInputs(?array $redirectUris): ?array
    {
        if ($redirectUris === null) {
            return null;
        }

        return array_map(static fn (string $uri): RedirectUriInput => new RedirectUriInput(uri: $uri), $redirectUris);
    }

    private static function toRequestOptions(?string $idempotencyKey): ?RequestOptions
    {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return null;
        }

        return new RequestOptions(idempotencyKey: $idempotencyKey);
    }
}
