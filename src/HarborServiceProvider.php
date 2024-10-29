<?php

namespace Emilsundberg\Harbor;

use Emilsundberg\Harbor\Commands\DockCommand;
use Illuminate\Support\ServiceProvider;
use Emilsundberg\Harbor\Commands\DepartCommand;

class HarborServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DockCommand::class,
                DepartCommand::class,
            ]);
        }
    }

    public function register()
    {
        //
    }
}
