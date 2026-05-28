<?php

namespace Webkul\NativephpRemote;

use Illuminate\Support\ServiceProvider;
use Webkul\NativephpRemote\Console\Commands\PatchCommand;

class NativephpRemoteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nativephp-remote.php', 'nativephp-remote');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'nativephp-remote');

        if ($this->app->runningInConsole()) {
            $this->commands([
                PatchCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/nativephp-remote.php' => config_path('nativephp-remote.php'),
            ], 'nativephp-remote-config');
        }
    }
}
