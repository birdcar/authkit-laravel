<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Install\Plans;

interface MigrationPlan
{
    public function generate(string $projectPath): string;

    public function getRiskLevel(): string;

    public function getSummary(): string;
}
