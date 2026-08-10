<?php

declare(strict_types=1);

namespace Authkit\Authkit\Vault;

use Illuminate\Database\Eloquent\Model;

final class DefaultVaultKeyContextResolver implements ResolvesVaultKeyContext
{
    /**
     * @return array<string, string>
     */
    public function resolve(Model $model, string $attribute): array
    {
        $context = [
            'model' => $model::class,
            'attribute' => $attribute,
        ];

        // Full override hook: a model can take complete control of its own
        // context via vaultKeyContext(string $attribute): array<string, string>.
        // Base keys survive unless the override explicitly redefines them.
        if (method_exists($model, 'vaultKeyContext')) {
            return $this->mergeOverride($context, $model->vaultKeyContext($attribute));
        }

        // Org-awareness hook, duck-typed rather than imported from Phase 3's
        // HasWorkosOrganization trait: Vault's only prereq is Phase 1, so a
        // Phase-3 class dependency is not guaranteed to exist yet. Any model
        // that exposes workosOrganizationId(): ?string gets automatic org
        // isolation with no wiring required.
        if (method_exists($model, 'workosOrganizationId')) {
            $organizationId = $model->workosOrganizationId();

            if (is_string($organizationId) && $organizationId !== '') {
                $context['organization_id'] = $organizationId;
            }
        }

        return $context;
    }

    /**
     * @param  array<string, string>  $context
     * @return array<string, string>
     */
    private function mergeOverride(array $context, mixed $override): array
    {
        if (! is_array($override)) {
            return $context;
        }

        foreach ($override as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (is_string($value)) {
                $context[$key] = $value;
            } elseif (is_scalar($value)) {
                // The SDK's context is array<string, string>; a scalar (e.g. an
                // int tenant id) is stringified rather than silently dropped —
                // dropping it would be a key-context drift, the exact failure
                // mode this component exists to prevent.
                $context[$key] = (string) $value;
            }
        }

        return $context;
    }
}
