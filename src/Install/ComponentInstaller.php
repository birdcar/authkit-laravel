<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install;

use Illuminate\Console\Command;

interface ComponentInstaller
{
    public function install(Command $command): void;

    public function describe(): string;
}
