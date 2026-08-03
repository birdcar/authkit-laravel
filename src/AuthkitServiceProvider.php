<?php

declare(strict_types=1);

namespace Authkit\Authkit;

use Authkit\Authkit\Console\Commands\AuthkitCommand;
use Illuminate\Support\ServiceProvider;

class AuthkitServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/authkit-laravel.php', 'authkit-laravel');

        $this->app->singleton(Authkit::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/authkit-laravel.php');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'authkit-laravel');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'authkit-laravel');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/authkit-laravel.php' => config_path('authkit-laravel.php'),
        ], ['authkit-laravel', 'authkit-laravel-config']);

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/authkit-laravel'),
        ], ['authkit-laravel', 'authkit-laravel-views']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/authkit-laravel'),
        ], ['authkit-laravel', 'authkit-laravel-lang']);

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/authkit-laravel'),
        ], ['authkit-laravel', 'authkit-laravel-assets']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['authkit-laravel', 'authkit-laravel-migrations']);

        $this->commands([
            AuthkitCommand::class,
        ]);
    }
}
