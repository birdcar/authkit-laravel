<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install;

use Illuminate\Console\Command;

class WebhookInstaller implements ComponentInstaller
{
    public function install(Command $command): void
    {
        $command->info('Webhook route enabled: /webhooks/workos');
        $command->newLine();
        $command->line('  <fg=yellow>Configure webhook in WorkOS Dashboard:</>');
        $command->line('    URL: '.config('app.url').'/webhooks/workos');
        $command->line('    Events: user.created, user.updated, user.deleted');
    }

    public function describe(): string
    {
        return 'Webhook endpoint for user sync';
    }
}
