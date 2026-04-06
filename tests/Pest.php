<?php

declare(strict_types=1);

use WorkOS\AuthKit\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');

/**
 * Build a Symfony-escaped command string from an array of arguments.
 * Symfony Process serializes array args as 'word' 'word' 'word' for use with Process::fake() keys.
 *
 * @param  string[]  $args
 */
function cmdStr(array $args): string
{
    return implode(' ', array_map(fn ($a) => "'$a'", $args));
}

/**
 * Normalize a Process command (array or string) to an array for assertions.
 *
 * @param  string|array<int,string>  $cmd
 * @return string[]
 */
function cmdArray(string|array $cmd): array
{
    return is_array($cmd) ? $cmd : explode(' ', $cmd);
}
