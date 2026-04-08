<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Livewire\Concerns;

trait WithWidgetTheme
{
    public ?string $accentColor = null;

    public ?string $borderColor = null;

    public ?string $backgroundColor = null;

    public ?string $foregroundColor = null;

    public string $appearance = 'light';

    protected function themeStyles(): string
    {
        $vars = [];

        if ($this->accentColor !== null) {
            $vars[] = "--woswidgets-accent-color: {$this->accentColor}";
        }
        if ($this->borderColor !== null) {
            $vars[] = "--woswidgets-border-color: {$this->borderColor}";
        }
        if ($this->backgroundColor !== null) {
            $vars[] = "--woswidgets-background-color: {$this->backgroundColor}";
        }
        if ($this->foregroundColor !== null) {
            $vars[] = "--woswidgets-foreground-color: {$this->foregroundColor}";
        }

        return implode('; ', $vars);
    }

    protected function themeClass(): string
    {
        return "woswidgets-root woswidgets-{$this->appearance}";
    }
}
