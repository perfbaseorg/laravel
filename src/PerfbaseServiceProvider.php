<?php

namespace Perfbase\Laravel;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Perfbase\SDK\Config;
use Perfbase\SDK\Config as PerfbaseConfig;
use Perfbase\SDK\Perfbase;
use Perfbase\SDK\Perfbase as PerfbaseClient;

class PerfbaseServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/perfbase.php' => config_path('perfbase.php'),
            ], 'perfbase-config');
        }
    }

    public function register()
    {
        // Register the config
        $this->mergeConfigFrom(__DIR__ . '/../config/perfbase.php', 'perfbase');

        /**
         * Bind the Config class to the container
         */
        $this->app->bind(Config::class, function ($app) {

            /**
             * @var array<string, mixed> $config
             * @phpstan-ignore offsetAccess.nonOffsetAccessible
             */
            $config = $app['config'];

            /** @var int $flags */
            $flags = $config['perfbase.flags'];

            /** @var string|null $proxy */
            $proxy = $config['perfbase.sending.proxy'];

            /** @var numeric $timeout */
            $timeout = $config['perfbase.sending.timeout'];

            /** @var string $apiKey */
            $apiKey = $config['perfbase.api_key'];

            return Config::fromArray([
                'api_key' => $apiKey,
                'flags' => $flags,
                'proxy' => $proxy,
                'timeout' => $timeout,
            ]);
        });

        $this->app->singleton(Perfbase::class, function (Application $app) {

            /** @var PerfbaseConfig $config */
            $config = $app->make(PerfbaseConfig::class);

            // Start a new perfbase instance
            return new PerfbaseClient($config);
        });
    }
}
