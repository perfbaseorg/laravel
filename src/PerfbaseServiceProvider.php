<?php

namespace Perfbase\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Perfbase\SDK\Config;

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

            /** @var array<string, mixed> $features */
            $features = $config['perfbase.profiler_features'];

            /** @var string $apiKey */
            $apiKey = $config['perfbase.api_key'];

            return Config::fromArray([
                'api_key' => $apiKey,
                'ignored_functions' => $features['ignored_functions'],
                'use_coarse_clock' => $features['use_coarse_clock'],
                'track_file_compilation' => $features['track_file_compilation'],
                'track_memory_allocation' => $features['track_memory_allocation'],
                'track_cpu_time' => $features['track_cpu_time'],
                'track_pdo' => $features['track_pdo'],
                'track_http' => $features['track_http'],
                'track_caches' => $features['track_caches'],
                'track_mongodb' => $features['track_mongodb'],
                'track_elasticsearch' => $features['track_elasticsearch'],
                'track_queues' => $features['track_queues'],
                'track_aws_sdk' => $features['track_aws_sdk'],
                'track_file_operations' => $features['track_file_operations'],
                'proxy' => $features['proxy'],
                'timeout' => $features['timeout'],
            ]);
        });
    }
}
