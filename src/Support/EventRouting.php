<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Support;

use WorkOS\AuthKit\Http\Controllers\WebhookController;

class EventRouting
{
    /**
     * Longest prefixes first to avoid false matches.
     *
     * @var array<string, string>
     */
    private const array CATEGORY_MAP = [
        'organization_membership.' => 'organization_membership',
        'organization.' => 'organization',
        'authentication.' => 'authentication',
        'session.' => 'session',
        'dsync.' => 'dsync',
        'user.' => 'user',
    ];

    /**
     * @param  array<string, string>  $categories
     * @param  array<string, string>  $overrides
     */
    public function __construct(
        private readonly array $categories,
        private readonly array $overrides,
    ) {}

    public function methodFor(string $eventType): string
    {
        if (isset($this->overrides[$eventType])) {
            return $this->overrides[$eventType];
        }

        foreach (self::CATEGORY_MAP as $prefix => $category) {
            if (str_starts_with($eventType, $prefix)) {
                return $this->categories[$category] ?? 'webhooks';
            }
        }

        return 'webhooks';
    }

    public function shouldProcessVia(string $eventType, string $method): bool
    {
        $configured = $this->methodFor($eventType);

        return $configured === $method || $configured === 'both';
    }

    /**
     * @return array<string>
     */
    public function eventTypesFor(string $method): array
    {
        return array_keys(
            array_filter(
                WebhookController::EVENT_MAP,
                fn (string $class, string $type): bool => $this->shouldProcessVia($type, $method),
                ARRAY_FILTER_USE_BOTH,
            )
        );
    }
}
