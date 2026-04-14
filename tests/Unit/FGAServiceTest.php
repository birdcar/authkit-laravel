<?php

declare(strict_types=1);

use WorkOS\AuthKit\FGA\FGAAccessResult;
use WorkOS\AuthKit\FGA\FGAResource;

it('parses FGAResource from string', function () {
    $resource = FGAResource::fromString('project:proj_123');

    expect($resource->resourceType)->toBe('project')
        ->and($resource->resourceId)->toBe('proj_123');
});

it('converts FGAResource to string', function () {
    $resource = new FGAResource('document', 'doc_456');

    expect($resource->toString())->toBe('document:doc_456');
});

it('handles resource IDs with colons', function () {
    $resource = FGAResource::fromString('namespace:type:id_with:colons');

    expect($resource->resourceType)->toBe('namespace')
        ->and($resource->resourceId)->toBe('type:id_with:colons');
});

it('creates FGAAccessResult with allowed true', function () {
    $resource = new FGAResource('project', 'proj_1');
    $result = new FGAAccessResult(
        allowed: true,
        userId: 'user_123',
        permission: 'edit',
        resource: $resource,
    );

    expect($result->allowed)->toBeTrue()
        ->and($result->userId)->toBe('user_123')
        ->and($result->permission)->toBe('edit')
        ->and($result->resource->toString())->toBe('project:proj_1');
});

it('creates FGAAccessResult with allowed false', function () {
    $resource = new FGAResource('project', 'proj_1');
    $result = new FGAAccessResult(
        allowed: false,
        userId: 'user_123',
        permission: 'delete',
        resource: $resource,
    );

    expect($result->allowed)->toBeFalse();
});
