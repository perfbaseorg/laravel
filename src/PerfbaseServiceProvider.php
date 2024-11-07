<?php

namespace Perfbase\Laravel;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Perfbase\Laravel\Middleware\ProfilerMiddleware;
use Perfbase\SDK\Client;
use Perfbase\SDK\Config;
use Perfbase\Laravel\Commands\SyncProfilesCommand;
use Illuminate\Console\Scheduling\Schedule;
use Perfbase\Laravel\Commands\ClearProfilesCommand;

class PerfbaseServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/perfbase.php' => config_path('perfbase.php'),
            ], 'perfbase-config');

            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

            $this->commands([
                SyncProfilesCommand::class,
                ClearProfilesCommand::class,
            ]);
        }

        if (config('perfbase.enabled')) {
            $this->bootProfiler();
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (in_array(config('perfbase.cache'), ['database', 'file'], true)) {
                $schedule->command('perfbase:sync-profiles')
                    ->everyMinutes(config('perfbase.sync_interval', 60))
                    ->withoutOverlapping();
            }
        });
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/perfbase.php', 'perfbase');

        $this->app->singleton(Client::class, function ($app) {
            return new Client(
                Config::fromArray($app['config']->get('perfbase'))
            );
        });
    }

    private function bootProfiler()
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->pushMiddleware(ProfilerMiddleware::class);
    }
}
