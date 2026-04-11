<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use WorkOS\AuthKit\Http\Controllers\WebhookController;

beforeEach(function () {
    $apiKey = env('WORKOS_API_KEY', '');

    if (empty($apiKey) || ! str_starts_with($apiKey, 'sk_test_')) {
        $this->markTestSkipped('Requires WORKOS_API_KEY env var set to a test key (sk_test_*)');
    }
});

it('has only valid event names in EVENT_MAP', function () {
    $apiKey = env('WORKOS_API_KEY');
    $eventNames = array_keys(WebhookController::EVENT_MAP);

    $response = Http::withToken($apiKey)
        ->acceptJson()
        ->get('https://api.workos.com/events', [
            'events' => $eventNames,
            'limit' => 1,
        ]);

    expect($response->status())
        ->toBe(200, "WorkOS rejected one or more event names: {$response->body()}");
});
