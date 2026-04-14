<?php

declare(strict_types=1);

use WorkOS\AuthKit\Support\EventRouting;

$defaultCategories = [
    'user' => 'webhooks',
    'organization' => 'webhooks',
    'organization_membership' => 'webhooks',
    'dsync' => 'events_api',
    'session' => 'webhooks',
    'authentication' => 'webhooks',
];

describe('EventRouting', function () use ($defaultCategories) {

    describe('methodFor', function () use ($defaultCategories) {
        it('routes user events to webhooks by default', function () use ($defaultCategories) {
            $routing = new EventRouting($defaultCategories, []);

            expect($routing->methodFor('user.created'))->toBe('webhooks');
            expect($routing->methodFor('user.updated'))->toBe('webhooks');
            expect($routing->methodFor('user.deleted'))->toBe('webhooks');
        });

        it('routes dsync events to events_api by default', function () use ($defaultCategories) {
            $routing = new EventRouting($defaultCategories, []);

            expect($routing->methodFor('dsync.user.created'))->toBe('events_api');
        });

        it('resolves organization_membership before organization', function () use ($defaultCategories) {
            $routing = new EventRouting($defaultCategories, []);

            expect($routing->methodFor('organization_membership.created'))->toBe('webhooks');
            expect($routing->methodFor('organization.created'))->toBe('webhooks');
        });

        it('resolves organization_membership independently from organization', function () {
            $routing = new EventRouting([
                'organization' => 'webhooks',
                'organization_membership' => 'events_api',
            ], []);

            expect($routing->methodFor('organization_membership.created'))->toBe('events_api');
            expect($routing->methodFor('organization.created'))->toBe('webhooks');
        });

        it('applies per-event-type overrides over category defaults', function () use ($defaultCategories) {
            $routing = new EventRouting($defaultCategories, [
                'user.deleted' => 'events_api',
            ]);

            expect($routing->methodFor('user.created'))->toBe('webhooks');
            expect($routing->methodFor('user.deleted'))->toBe('events_api');
        });

        it('defaults unknown event types to webhooks', function () use ($defaultCategories) {
            $routing = new EventRouting($defaultCategories, []);

            expect($routing->methodFor('something.unknown'))->toBe('webhooks');
        });

        it('defaults to webhooks when category is missing from config', function () {
            $routing = new EventRouting([], []);

            expect($routing->methodFor('user.created'))->toBe('webhooks');
        });
    });

    describe('shouldProcessVia', function () use ($defaultCategories) {
        it('returns true for exact method match', function () use ($defaultCategories) {
            $routing = new EventRouting($defaultCategories, []);

            expect($routing->shouldProcessVia('user.created', 'webhooks'))->toBeTrue();
            expect($routing->shouldProcessVia('user.created', 'events_api'))->toBeFalse();
            expect($routing->shouldProcessVia('dsync.user.created', 'events_api'))->toBeTrue();
            expect($routing->shouldProcessVia('dsync.user.created', 'webhooks'))->toBeFalse();
        });

        it('returns true for both methods when configured as both', function () {
            $routing = new EventRouting(['user' => 'both'], []);

            expect($routing->shouldProcessVia('user.created', 'webhooks'))->toBeTrue();
            expect($routing->shouldProcessVia('user.created', 'events_api'))->toBeTrue();
        });
    });

    describe('eventTypesFor', function () use ($defaultCategories) {
        it('returns dsync types for events_api with default config', function () use ($defaultCategories) {
            $routing = new EventRouting($defaultCategories, []);

            $eventsApiTypes = $routing->eventTypesFor('events_api');

            expect($eventsApiTypes)->toContain('dsync.activated')
                ->and($eventsApiTypes)->toContain('dsync.user.created')
                ->and($eventsApiTypes)->toContain('dsync.group.created')
                ->and($eventsApiTypes)->not->toContain('user.created');
        });

        it('returns all non-dsync types for webhooks with default config', function () use ($defaultCategories) {
            $routing = new EventRouting($defaultCategories, []);

            $webhookTypes = $routing->eventTypesFor('webhooks');

            expect($webhookTypes)->toContain('user.created');
            expect($webhookTypes)->toContain('organization.created');
            expect($webhookTypes)->toContain('organization_membership.created');
            expect($webhookTypes)->toContain('session.created');
            expect($webhookTypes)->toContain('authentication.oauth_succeeded');
        });

        it('includes overridden types in the correct method list', function () use ($defaultCategories) {
            $routing = new EventRouting($defaultCategories, [
                'user.deleted' => 'events_api',
            ]);

            $eventsApiTypes = $routing->eventTypesFor('events_api');
            $webhookTypes = $routing->eventTypesFor('webhooks');

            expect($eventsApiTypes)->toContain('user.deleted');
            expect($webhookTypes)->not->toContain('user.deleted');
            expect($webhookTypes)->toContain('user.created');
        });

        it('includes both-routed types in both method lists', function () {
            $routing = new EventRouting(['user' => 'both', 'organization' => 'webhooks'], []);

            $eventsApiTypes = $routing->eventTypesFor('events_api');
            $webhookTypes = $routing->eventTypesFor('webhooks');

            expect($eventsApiTypes)->toContain('user.created');
            expect($webhookTypes)->toContain('user.created');
            expect($webhookTypes)->toContain('organization.created');
        });
    });
});
