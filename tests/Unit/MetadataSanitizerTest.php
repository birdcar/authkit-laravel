<?php

declare(strict_types=1);

use Authkit\Authkit\AuditLogs\Support\MetadataSanitizer;
use Illuminate\Support\Facades\Log;

// Test path: none (pure unit) — no WorkOS wire call is involved.

it('passes well-formed metadata through unchanged without warning', function (): void {
    Log::spy();

    $metadata = ['plan' => 'enterprise', 'seats' => 25, 'note' => str_repeat('a', 500)];

    expect(MetadataSanitizer::sanitize($metadata, context: 'subscription.updated'))->toBe($metadata);

    Log::shouldNotHaveReceived('warning');
});

it('caps metadata at 50 keys and warns naming the action', function (): void {
    Log::spy();

    $metadata = [];

    foreach (range(1, 51) as $i) {
        $metadata["key_{$i}"] = "value {$i}";
    }

    $sanitized = MetadataSanitizer::sanitize($metadata, context: 'post.update');

    expect($sanitized)->toHaveCount(50)
        ->and(array_key_exists('key_1', $sanitized))->toBeTrue()
        ->and(array_key_exists('key_50', $sanitized))->toBeTrue()
        ->and(array_key_exists('key_51', $sanitized))->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'authkit: audit log metadata truncated to WorkOS limits'
            && $context['context'] === 'post.update'
            && $context['dropped_keys'] === 1)
        ->once();
});

it('clips a 501-character string value to 500 characters and warns', function (): void {
    Log::spy();

    $sanitized = MetadataSanitizer::sanitize(['note' => str_repeat('x', 501)], context: 'post.update');

    expect($sanitized['note'])->toHaveLength(500);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $context['clipped_values'] === 1)
        ->once();
});

it('leaves non-string values alone when clipping', function (): void {
    Log::spy();

    $metadata = ['ids' => range(1, 600), 'flag' => true];

    expect(MetadataSanitizer::sanitize($metadata, context: 'post.update'))->toBe($metadata);

    Log::shouldNotHaveReceived('warning');
});
