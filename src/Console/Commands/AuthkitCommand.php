<?php

declare(strict_types=1);

namespace Authkit\Authkit\Console\Commands;

use Illuminate\Console\Command;

class AuthkitCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'authkit-laravel:placeholder';

    /**
     * The command description.
     */
    protected $description = 'Placeholder Artisan command shipped by the package authkit-laravel.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('Authkit placeholder command executed.');

        return self::SUCCESS;
    }
}
