<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneSessionsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'workos:prune-sessions
        {--hours=24 : Delete sessions older than this many hours}';

    /**
     * @var string
     */
    protected $description = 'Prune expired WorkOS sessions from the database';

    public function handle(): int
    {
        /** @var int $hours */
        $hours = $this->option('hours');

        $deleted = DB::table('sessions')
            ->where('last_activity', '<', now()->subHours($hours)->timestamp)
            ->delete();

        $this->info("Pruned {$deleted} expired sessions.");

        return self::SUCCESS;
    }
}
