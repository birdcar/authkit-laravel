# Implementation Spec: AuthKit Laravel - Phase 5

**PRD**: ./prd-phase-5.md
**Estimated Effort**: L (Large)

## Technical Approach

Phase 5 implements webhook handling with signature verification using WorkOS SDK. The controller verifies signatures, then dispatches Laravel events for each webhook type. Default listeners sync data to local database.

The Events API example is a standalone artisan command demonstrating SSE consumption for real-time event streaming (recommended for high-volume scenarios).

## File Changes

### New Files

| File Path | Purpose |
|-----------|---------|
| `src/Http/Controllers/WebhookController.php` | Webhook handler |
| `src/Events/WebhookReceived.php` | Generic webhook event |
| `src/Events/Webhooks/WorkOSUserCreated.php` | User created event |
| `src/Events/Webhooks/WorkOSUserUpdated.php` | User updated event |
| `src/Events/Webhooks/WorkOSUserDeleted.php` | User deleted event |
| `src/Events/Webhooks/WorkOSOrganizationCreated.php` | Org created event |
| `src/Events/Webhooks/WorkOSOrganizationUpdated.php` | Org updated event |
| `src/Events/Webhooks/WorkOSOrganizationDeleted.php` | Org deleted event |
| `src/Events/Webhooks/WorkOSMembershipCreated.php` | Membership event |
| `src/Events/Webhooks/WorkOSMembershipUpdated.php` | Membership event |
| `src/Events/Webhooks/WorkOSMembershipDeleted.php` | Membership event |
| `src/Events/Webhooks/WorkOSSessionCreated.php` | Session event |
| `src/Events/Webhooks/WorkOSSessionRevoked.php` | Session event |
| `src/Listeners/SyncUserFromWebhook.php` | Default user sync |
| `src/Listeners/SyncOrganizationFromWebhook.php` | Default org sync |
| `src/Listeners/SyncMembershipFromWebhook.php` | Default membership sync |
| `src/Commands/EventsListenCommand.php` | Events API example |
| `routes/webhooks.php` | Webhook routes |
| `tests/Feature/WebhookTest.php` | Webhook tests |
| `tests/Unit/WebhookSignatureTest.php` | Signature verification |

### Modified Files

| File Path | Changes |
|-----------|---------|
| `src/WorkOSServiceProvider.php` | Register webhook routes, event listeners |
| `config/workos.php` | Add webhook config section |

## Implementation Details

### WebhookController

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use WorkOS\AuthKit\Events\WebhookReceived;
use WorkOS\Webhook;

class WebhookController
{
    private const EVENT_MAP = [
        'user.created' => Events\Webhooks\WorkOSUserCreated::class,
        'user.updated' => Events\Webhooks\WorkOSUserUpdated::class,
        'user.deleted' => Events\Webhooks\WorkOSUserDeleted::class,
        'organization.created' => Events\Webhooks\WorkOSOrganizationCreated::class,
        'organization.updated' => Events\Webhooks\WorkOSOrganizationUpdated::class,
        'organization.deleted' => Events\Webhooks\WorkOSOrganizationDeleted::class,
        'organization_membership.created' => Events\Webhooks\WorkOSMembershipCreated::class,
        'organization_membership.updated' => Events\Webhooks\WorkOSMembershipUpdated::class,
        'organization_membership.deleted' => Events\Webhooks\WorkOSMembershipDeleted::class,
        'session.created' => Events\Webhooks\WorkOSSessionCreated::class,
        'session.revoked' => Events\Webhooks\WorkOSSessionRevoked::class,
    ];

    public function handle(Request $request): Response
    {
        $payload = $request->getContent();
        $signature = $request->header('WorkOS-Signature');
        $secret = config('workos.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);
        } catch (\Exception $e) {
            report($e);
            return response('Invalid signature', 400);
        }

        // Dispatch generic event first
        event(new WebhookReceived($event['event'], $event['data']));

        // Dispatch specific event if mapped
        $eventClass = self::EVENT_MAP[$event['event']] ?? null;
        if ($eventClass) {
            event(new $eventClass($event['data']));
        }

        return response('OK', 200);
    }
}
```

### Webhook Event Classes

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Events\Webhooks;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkOSUserUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly array $data,
    ) {}

    public function userId(): string
    {
        return $this->data['id'];
    }

    public function email(): string
    {
        return $this->data['email'];
    }

    public function firstName(): ?string
    {
        return $this->data['first_name'] ?? null;
    }

    public function lastName(): ?string
    {
        return $this->data['last_name'] ?? null;
    }
}
```

### Default Sync Listener

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Listeners;

use WorkOS\AuthKit\Events\Webhooks\WorkOSUserUpdated;

class SyncUserFromWebhook
{
    public function handle(WorkOSUserUpdated $event): void
    {
        $userModel = config('workos.user_model');

        $user = $userModel::findByWorkOSId($event->userId());

        if ($user) {
            $user->update([
                'email' => $event->email(),
                'name' => trim($event->firstName() . ' ' . $event->lastName()),
            ]);
        }
    }
}
```

### Events API Example Command

```php
<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class EventsListenCommand extends Command
{
    protected $signature = 'workos:events-listen
        {--timeout=0 : Connection timeout (0 = infinite)}';

    protected $description = 'Listen to WorkOS Events API (example implementation)';

    public function handle(): int
    {
        $this->info('Connecting to WorkOS Events API...');
        $this->warn('Note: This is an example. For production, use a dedicated process manager.');

        $apiKey = config('workos.api_key');
        $url = 'https://api.workos.com/events';

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Accept' => 'text/event-stream',
            ])->withOptions([
                'stream' => true,
                'timeout' => $this->option('timeout'),
            ])->get($url);

            $body = $response->getBody();

            while (!$body->eof()) {
                $line = $body->read(8192);

                if (str_starts_with($line, 'data:')) {
                    $data = json_decode(substr($line, 5), true);
                    $this->processEvent($data);
                }
            }
        } catch (\Exception $e) {
            $this->error("Connection failed: {$e->getMessage()}");
            $this->info('Reconnecting in 5 seconds...');
            sleep(5);
            return $this->handle(); // Reconnect
        }

        return self::SUCCESS;
    }

    private function processEvent(array $event): void
    {
        $this->info("Received: {$event['event']}");

        // Dispatch same events as webhooks
        event(new WebhookReceived($event['event'], $event['data']));

        $eventClass = WebhookController::EVENT_MAP[$event['event']] ?? null;
        if ($eventClass) {
            event(new $eventClass($event['data']));
        }
    }
}
```

### Webhook Routes

```php
// routes/webhooks.php
<?php

use Illuminate\Support\Facades\Route;
use WorkOS\AuthKit\Http\Controllers\WebhookController;

Route::post('/', [WebhookController::class, 'handle'])
    ->name('workos.webhook')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
```

### Service Provider Updates

```php
// In WorkOSServiceProvider::boot()
protected function configureWebhooks(): void
{
    if (!config('workos.webhooks.enabled', true)) {
        return;
    }

    Route::group([
        'prefix' => config('workos.webhooks.prefix', 'webhooks/workos'),
        'middleware' => [],
    ], fn () => $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php'));
}

protected function configureEventListeners(): void
{
    if (!config('workos.webhooks.sync_enabled', true)) {
        return;
    }

    Event::listen(WorkOSUserUpdated::class, SyncUserFromWebhook::class);
    Event::listen(WorkOSUserCreated::class, SyncUserFromWebhook::class);
    Event::listen(WorkOSOrganizationUpdated::class, SyncOrganizationFromWebhook::class);
    Event::listen(WorkOSMembershipCreated::class, SyncMembershipFromWebhook::class);
    // ... other listeners
}
```

## API Design

### Webhook Endpoint

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/webhooks/workos` | Receive WorkOS webhooks |

### Supported Webhook Events

| Event Type | Laravel Event Class |
|------------|---------------------|
| `user.created` | `WorkOSUserCreated` |
| `user.updated` | `WorkOSUserUpdated` |
| `user.deleted` | `WorkOSUserDeleted` |
| `organization.created` | `WorkOSOrganizationCreated` |
| `organization.updated` | `WorkOSOrganizationUpdated` |
| `organization.deleted` | `WorkOSOrganizationDeleted` |
| `organization_membership.*` | `WorkOSMembership*` |
| `session.created` | `WorkOSSessionCreated` |
| `session.revoked` | `WorkOSSessionRevoked` |

## Testing Requirements

**Key test cases**:
- Valid signature passes verification
- Invalid signature returns 400
- `WebhookReceived` event fires for all webhooks
- Specific event classes fire for mapped types
- Default listeners sync data correctly
- Events API command connects and processes events

## Validation Commands

```bash
./vendor/bin/pest tests/Unit/WebhookSignatureTest.php
./vendor/bin/pest tests/Feature/WebhookTest.php
```

---

*This spec is ready for implementation.*
