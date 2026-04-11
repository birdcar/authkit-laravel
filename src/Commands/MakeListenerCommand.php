<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use WorkOS\AuthKit\Events\WorkOSEventReceived;
use WorkOS\AuthKit\Http\Controllers\WebhookController;

class MakeListenerCommand extends Command
{
    /** @var string */
    protected $signature = 'workos:make-listener
        {name? : The listener class name}
        {--events=* : Event class short names to handle (skip interactive prompt)}';

    /** @var string */
    protected $description = 'Create a new WorkOS event listener';

    public function handle(Filesystem $files): int
    {
        $availableEvents = $this->availableEvents();
        $selectedEvents = $this->resolveEvents($availableEvents);

        if (empty($selectedEvents)) {
            $this->error('No events selected.');

            return self::FAILURE;
        }

        $name = $this->resolveName($selectedEvents);
        $path = app_path("Listeners/{$name}.php");

        if ($files->exists($path)) {
            if (! $this->confirm("File {$path} already exists. Overwrite?", false)) {
                $this->info('Aborted.');

                return self::SUCCESS;
            }
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $this->generateClass($name, $selectedEvents));

        $this->components->info("Listener created: app/Listeners/{$name}.php");
        $this->line('Events: '.implode(', ', array_map(fn (string $class) => class_basename($class), $selectedEvents)));

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function availableEvents(): array
    {
        $events = [];

        foreach (WebhookController::EVENT_MAP as $eventClass) {
            $short = class_basename($eventClass);
            $events[$short] = $eventClass;
        }

        $events['WorkOSEventReceived'] = WorkOSEventReceived::class;

        return $events;
    }

    /**
     * @param  array<string, string>  $availableEvents
     * @return array<string>
     */
    private function resolveEvents(array $availableEvents): array
    {
        /** @var array<string> $eventsOption */
        $eventsOption = $this->option('events');

        if (! empty($eventsOption)) {
            return array_values(array_filter(
                array_map(fn (string $name) => $availableEvents[$name] ?? null, $eventsOption),
            ));
        }

        /** @var array<string> $selected */
        $selected = $this->choice(
            'Which events should this listener handle?',
            array_keys($availableEvents),
            multiple: true,
        );

        return array_values(array_map(fn (string $name) => $availableEvents[$name], $selected));
    }

    /**
     * @param  array<string>  $events
     */
    private function resolveName(array $events): string
    {
        /** @var string|null $nameArg */
        $nameArg = $this->argument('name');

        if ($nameArg !== null) {
            return $nameArg;
        }

        $suggestion = $this->suggestName($events);

        /** @var string $name */
        $name = $this->ask('Listener class name', $suggestion);

        return $name;
    }

    /**
     * @param  array<string>  $events
     */
    private function suggestName(array $events): string
    {
        if (count($events) === 1 && $events[0] === WorkOSEventReceived::class) {
            return 'HandleWorkOSEvents';
        }

        $subjects = [];
        foreach ($events as $class) {
            $base = class_basename($class);
            $base = preg_replace('/^WorkOS/', '', $base) ?? $base;
            $base = preg_replace('/(Created|Updated|Deleted)$/', '', $base) ?? $base;
            if ($base !== '' && ! in_array($base, $subjects, true)) {
                $subjects[] = $base;
            }
        }

        if (count($subjects) === 1) {
            return 'Sync'.$subjects[0];
        }

        return 'HandleWorkOSEvents';
    }

    /**
     * @param  array<string>  $events
     */
    private function generateClass(string $name, array $events): string
    {
        $namespace = $this->laravel->getNamespace().'Listeners';
        $imports = [];

        foreach ($events as $class) {
            $imports[] = "use {$class};";
        }

        $imports[] = 'use WorkOS\AuthKit\Listeners\Concerns\HandlesWorkOSEvents;';
        sort($imports);
        $importsBlock = implode("\n", $imports);

        $typeHint = implode('|', array_map(fn (string $class) => class_basename($class), $events));

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        {$importsBlock}

        class {$name}
        {
            use HandlesWorkOSEvents;

            public function handle({$typeHint} \$event): void
            {
                //
            }
        }
        PHP;
    }
}
