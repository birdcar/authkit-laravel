<?php

declare(strict_types=1);

use Authkit\Authkit\Authorization\ResourceTarget;
use WorkOS\Service\ResourceTargetByExternalId;
use WorkOS\Service\ResourceTargetById;

// Test path: zero-HTTP unit — pure DTO conversion.

it('converts an id target to the SDK ResourceTargetById', function (): void {
    $target = ResourceTarget::byId('auth_res_123')->toSdkTarget();

    expect($target)->toBeInstanceOf(ResourceTargetById::class)
        ->and($target->resourceId)->toBe('auth_res_123');
});

it('converts an external-id target to the SDK ResourceTargetByExternalId', function (): void {
    $target = ResourceTarget::byExternalId('42', 'project')->toSdkTarget();

    expect($target)->toBeInstanceOf(ResourceTargetByExternalId::class)
        ->and($target->resourceExternalId)->toBe('42')
        ->and($target->resourceTypeSlug)->toBe('project');
});
