<?php

declare(strict_types=1);

namespace Authkit\Authkit\Console\Commands;

use Authkit\Authkit\Events\GenericWorkosEvent;
use Illuminate\Console\GeneratorCommand;

/**
 * Same generator pattern Laravel's own make:listener uses, but --event
 * resolves against this package's bounded typed-event list instead of the
 * app's Events/ folder, and anything else falls back to GenericWorkosEvent —
 * the recipe for listening to every WorkOS event type the package doesn't
 * model (dsync.*, role.*, future types), with no package change required.
 */
class MakeWorkosListenerCommand extends GeneratorCommand
{
    /**
     * The command signature.
     */
    protected $signature = 'make:workos-listener
        {name : The name of the listener}
        {--e|event= : A bounded WorkOS event short name (e.g. UserCreated); omit for the generic fallback}
        {--f|force : Overwrite the listener if it already exists}';

    /**
     * The command description.
     */
    protected $description = 'Create a new listener for a WorkOS sidecar/webhook event';

    /**
     * The type of class being generated.
     */
    protected $type = 'Listener';

    private const array TYPED_EVENTS = [
        'UserCreated', 'UserUpdated', 'UserDeleted',
        'OrganizationCreated', 'OrganizationUpdated', 'OrganizationDeleted',
        'OrganizationDomainCreated', 'OrganizationDomainUpdated', 'OrganizationDomainDeleted',
        'OrganizationDomainVerified', 'OrganizationDomainVerificationFailed',
        'OrganizationMembershipCreated', 'OrganizationMembershipUpdated', 'OrganizationMembershipDeleted',
    ];

    protected function getStub(): string
    {
        // An unrecognized --event (typo, or a real WorkOS type like
        // dsync.user.created that is intentionally outside the bounded set)
        // falls back to the generic stub rather than erroring — the generator
        // must never block a developer from listening to something.
        return in_array($this->option('event'), self::TYPED_EVENTS, true)
            ? $this->resolveStubPath('/stubs/workos-listener.typed.stub')
            : $this->resolveStubPath('/stubs/workos-listener.generic.stub');
    }

    protected function buildClass(mixed $name): string
    {
        $event = $this->option('event');

        $eventClass = is_string($event) && in_array($event, self::TYPED_EVENTS, true)
            ? "Authkit\\Authkit\\Events\\Workos\\{$event}"
            : GenericWorkosEvent::class;

        return str_replace(
            ['{{ event }}', '{{ eventNamespace }}'],
            [class_basename($eventClass), $eventClass],
            parent::buildClass($name),
        );
    }

    protected function getDefaultNamespace(mixed $rootNamespace): string
    {
        // Native `mixed` (the parent declares no type, so narrowing to string
        // is forbidden by contravariance); the inherited @param string phpdoc
        // types it for analysis.
        return $rootNamespace.'\Listeners';
    }

    private function resolveStubPath(string $stub): string
    {
        // Apps can publish an override next to their other custom stubs; the
        // package-shipped file is the default (make:listener precedent).
        return file_exists($customPath = $this->laravel->basePath(trim($stub, '/')))
            ? $customPath
            : __DIR__.$stub;
    }
}
