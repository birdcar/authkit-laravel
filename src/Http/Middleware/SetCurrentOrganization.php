<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;
use WorkOS\AuthKit\Auth\SessionManagerInterface;

class SetCurrentOrganization
{
    public function __construct(
        private readonly SessionManagerInterface $sessionManager,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $organization = $this->resolveOrganization($user);

            View::share('currentOrganization', $organization);
            $request->attributes->set('current_organization', $organization);
        }

        return $next($request);
    }

    private function resolveOrganization(Authenticatable $user): ?Model
    {
        $workosOrgId = $this->sessionManager->getOrganizationId();

        if (! $workosOrgId) {
            return null;
        }

        // Check if the user model has organizations relationship
        if (! method_exists($user, 'organizations')) {
            return $this->getOrganizationFromDatabase($workosOrgId);
        }

        // Try to find in user's organizations first (dynamic relationship)
        /** @var \Illuminate\Database\Eloquent\Collection<int, Model> $organizations */
        $organizations = $user->organizations; // @phpstan-ignore property.notFound

        /** @var Model|null $organization */
        $organization = $organizations->firstWhere('workos_id', $workosOrgId);

        if ($organization) {
            return $organization;
        }

        // Sync from WorkOS if not found
        return $this->syncOrganizationFromWorkOS($workosOrgId, $user);
    }

    private function getOrganizationFromDatabase(string $workosOrgId): ?Model
    {
        /** @var class-string<Model> $organizationModel */
        $organizationModel = config('workos.organization_model');

        /** @var Model|null $organization */
        $organization = $organizationModel::query()->where('workos_id', $workosOrgId)->first();

        return $organization;
    }

    private function syncOrganizationFromWorkOS(string $workosOrgId, Authenticatable $user): ?Model
    {
        try {
            $organizations = new \WorkOS\Organizations;
            $orgData = $organizations->getOrganization($workosOrgId);

            /** @var class-string<Model> $organizationModel */
            $organizationModel = config('workos.organization_model');

            // Check if the model has findOrCreateByWorkOS method
            if (method_exists($organizationModel, 'findOrCreateByWorkOS')) {
                /** @var Model $organization */
                $organization = $organizationModel::findOrCreateByWorkOS([
                    'id' => $orgData->raw['id'],
                    'name' => $orgData->raw['name'],
                    'slug' => $orgData->raw['slug'] ?? null,
                    'domains' => $orgData->raw['domains'] ?? [],
                ]);
            } else {
                // Fallback to firstOrCreate
                /** @var Model $organization */
                $organization = $organizationModel::query()->firstOrCreate(
                    ['workos_id' => $orgData->raw['id']],
                    [
                        'name' => $orgData->raw['name'],
                        'slug' => $orgData->raw['slug'] ?? null,
                    ]
                );
            }

            // Link user to organization if not already linked (requires organizations relationship)
            if (method_exists($user, 'organizations')) {
                /** @var \Illuminate\Database\Eloquent\Collection<int, Model> $userOrgs */
                $userOrgs = $user->organizations; // @phpstan-ignore property.notFound

                if (! $userOrgs->contains($organization->getKey())) {
                    /** @var callable $relationshipMethod */
                    $relationshipMethod = [$user, 'organizations'];
                    /** @var \Illuminate\Database\Eloquent\Relations\BelongsToMany<Model, Model> $relationship */
                    $relationship = $relationshipMethod();
                    $relationship->attach($organization->getKey(), ['role' => 'member']);

                    if (method_exists($user, 'load')) {
                        $user->load('organizations');
                    }
                }
            }

            return $organization;
        } catch (\Exception $e) {
            report($e);

            return null;
        }
    }
}
