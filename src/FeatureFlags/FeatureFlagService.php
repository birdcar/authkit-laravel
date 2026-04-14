<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\FeatureFlags;

use WorkOS\AuthKit\Auth\SessionManager;
use WorkOS\Organizations;
use WorkOS\Resource\FeatureFlag;

class FeatureFlagService
{
    public function __construct(
        private readonly SessionManager $session,
        private readonly Organizations $organizations,
    ) {}

    public function isEnabled(string $slug, ?string $organizationId = null): bool
    {
        if (! config('workos.features.feature_flags', true)) {
            return false;
        }

        $session = $this->session->getSession();
        if ($session !== null) {
            return $session->hasFeatureFlag($slug);
        }

        $orgId = $organizationId ?? $this->session->getOrganizationId();
        if ($orgId === null) {
            return false;
        }

        return $this->flagEnabledViaApi($slug, $orgId);
    }

    /**
     * @return array<FeatureFlag>
     */
    public function listForOrganization(string $organizationId): array
    {
        $result = $this->organizations->listOrganizationFeatureFlags($organizationId);

        /** @var array<FeatureFlag> */
        return $result->feature_flags ?? [];
    }

    private function flagEnabledViaApi(string $slug, string $organizationId): bool
    {
        try {
            $flags = $this->listForOrganization($organizationId);

            foreach ($flags as $flag) {
                if ($flag->slug === $slug) {
                    return true;
                }
            }

            return false;
        } catch (\Exception) {
            return false;
        }
    }
}
