<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use WorkOS\AuthKit\Events\WebhookReceived;
use WorkOS\AuthKit\Http\Controllers\WebhookController;

class EventsListenCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'workos:events-listen
        {--timeout=0 : Connection timeout in seconds (0 = infinite)}';

    /**
     * @var string
     */
    protected $description = 'Listen to WorkOS Events API (example implementation)';

    public function handle(): int
    {
        $this->info('Connecting to WorkOS Events API...');
        $this->warn('Note: This is an example. For production, use a dedicated process manager.');

        /** @var string $apiKey */
        $apiKey = config('workos.api_key');

        if (empty($apiKey)) {
            $this->error('WorkOS API key not configured.');

            return self::FAILURE;
        }

        $url = 'https://api.workos.com/events';
        /** @var int $timeout */
        $timeout = $this->option('timeout');

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Accept' => 'text/event-stream',
            ])->withOptions([
                'stream' => true,
                'timeout' => $timeout,
            ])->get($url);

            $body = $response->getBody();

            while (! $body->eof()) {
                $line = $body->read(8192);

                if (str_starts_with($line, 'data:')) {
                    /** @var array<string, mixed>|null $data */
                    $data = json_decode(substr($line, 5), true);
                    if ($data !== null) {
                        $this->processEvent($data);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->error("Connection failed: {$e->getMessage()}");
            $this->info('Reconnecting in 5 seconds...');
            sleep(5);

            return $this->handle();
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function processEvent(array $event): void
    {
        $eventType = $event['event'] ?? 'unknown';
        $eventData = $event['data'] ?? [];

        $this->info("Received: {$eventType}");

        event(new WebhookReceived($eventType, $eventData));

        $eventClass = WebhookController::EVENT_MAP[$eventType] ?? null;
        if ($eventClass !== null) {
            event(new $eventClass($eventData));
        }
    }
}
