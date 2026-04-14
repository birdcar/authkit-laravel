<?php

declare(strict_types=1);

use WorkOS\AuthKit\Auth\ApiKeyValidation;

it('creates ApiKeyValidation from response with org owner', function () {
    $response = [
        'id' => 'key_123',
        'owner' => ['type' => 'organization', 'id' => 'org_456'],
        'name' => 'Production Key',
        'obfuscated_value' => 'sk_test_***abc',
        'permissions' => ['read:data', 'write:data'],
        'last_used_at' => '2024-06-15T12:00:00Z',
        'created_at' => '2024-01-01T00:00:00Z',
        'updated_at' => '2024-06-01T00:00:00Z',
    ];

    $validation = ApiKeyValidation::fromResponse($response);

    expect($validation->id)->toBe('key_123')
        ->and($validation->ownerType)->toBe('organization')
        ->and($validation->ownerId)->toBe('org_456')
        ->and($validation->name)->toBe('Production Key')
        ->and($validation->obfuscatedValue)->toBe('sk_test_***abc')
        ->and($validation->permissions)->toBe(['read:data', 'write:data'])
        ->and($validation->organizationId)->toBe('org_456')
        ->and($validation->lastUsedAt)->toBe('2024-06-15T12:00:00Z')
        ->and($validation->createdAt)->toBe('2024-01-01T00:00:00Z')
        ->and($validation->updatedAt)->toBe('2024-06-01T00:00:00Z');
});

it('sets organizationId to null for non-org owners', function () {
    $response = [
        'id' => 'key_789',
        'owner' => ['type' => 'user', 'id' => 'user_123'],
        'name' => 'User Key',
        'obfuscated_value' => 'sk_***',
        'permissions' => [],
        'created_at' => '2024-01-01T00:00:00Z',
        'updated_at' => '2024-01-01T00:00:00Z',
    ];

    $validation = ApiKeyValidation::fromResponse($response);

    expect($validation->organizationId)->toBeNull()
        ->and($validation->ownerType)->toBe('user')
        ->and($validation->ownerId)->toBe('user_123');
});

it('handles missing optional fields gracefully', function () {
    $response = [
        'id' => 'key_min',
        'owner' => ['type' => 'organization', 'id' => 'org_1'],
        'created_at' => '2024-01-01T00:00:00Z',
        'updated_at' => '2024-01-01T00:00:00Z',
    ];

    $validation = ApiKeyValidation::fromResponse($response);

    expect($validation->name)->toBe('')
        ->and($validation->obfuscatedValue)->toBe('')
        ->and($validation->permissions)->toBe([])
        ->and($validation->lastUsedAt)->toBeNull();
});
