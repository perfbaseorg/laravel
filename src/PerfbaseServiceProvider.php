<?php

namespace Perfbase\Laravel;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Perfbase\Laravel\Profiling\AbstractProfiler;
use Perfbase\Laravel\Profiling\ConsoleProfiler;
use Perfbase\Laravel\Profiling\QueueProfiler;
use Perfbase\SDK\Config;
use Perfbase\SDK\Config as PerfbaseConfig;
use Perfbase\SDK\Perfbase;
use Perfbase\SDK\Perfbase as PerfbaseClient;

/**
 * Class PerfbaseServiceProvider
 */
class PerfbaseServiceProvider extends ServiceProvider
{

    /**
     * Used to store console profilers.
     * @var array <string, AbstractProfiler>
     */
    private array $spans = [];

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

        if (config('perfbase.enabled')) {
            $this->registerQueueListeners();
            $this->registerConsoleListeners();
        }
    }

    /**
     * Register the application services.
     * @return void
     */
    public function register()
    {
        // Register the config
        $this->mergeConfigFrom(__DIR__ . '/../config/perfbase.php', 'perfbase');

        /**
         * Bind the Config class to the container
         */
        $this->app->bind(Config::class, function (Application $app) {

            /**
             * @var array<string, mixed> $config
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

    /**
     * Register the queue listeners for job processing events.
     * @return void
     */
    private function registerQueueListeners(): void
    {
        // The job has started
        Event::listen(JobProcessing::class, function (JobProcessing $event) {
            $jobName = $this->getCommandFromJob($event->job);
            if (!isset($this->spans[$jobName])) {
                $profiler = new QueueProfiler($event->job, $jobName);
                $this->spans[$jobName] = $profiler;
                $profiler->startProfiling();
            }
        });

        // The job has stopped
        Event::listen(JobProcessed::class, function (JobProcessed $event) {
            $jobName = $this->getCommandFromJob($event->job);
            if (isset($this->spans[$jobName])) {
                $profiler = $this->spans[$jobName];
                $profiler->stopProfiling();
            }
        });

        // The job has failed
        Event::listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event) {
            $jobName = $this->getCommandFromJob($event->job);
            if (isset($this->spans[$jobName])) {
                $profiler = $this->spans[$jobName];
                // $profiler->setException($event->exception->getMessage());
                $profiler->stopProfiling();
            }
        });
    }

    /**
     * Register the console listeners for command events.
     * @return void
     */
    private function registerConsoleListeners(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            if ($event->command && $event->input && $event->output) {
                if (!isset($this->spans[$event->command])) {
                    $profiler = new ConsoleProfiler($event->command, $event->input, $event->output);
                    $this->spans[$event->command] = $profiler;
                    $profiler->startProfiling();
                }
            }
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event) {
            if ($event->command && $event->input && $event->output) {
                if (isset($this->spans[$event->command])) {
                    $profiler = $this->spans[$event->command];
                    // $profiler->setExitCode($event->exitCode);
                    $profiler->stopProfiling();
                    unset($this->spans[$event->command]); // Clean up the profiler after use
                }
            }
        });
    }

    /**
     * Get the command name from the job.
     * @param Job $job
     * @return string
     */
    private function getCommandFromJob(Job $job): string
    {
        $payload = $job->payload();

        // Try to get the display name first as it's the most reliable
        if (isset($payload['displayName'])) {
            return $payload['displayName'];
        }

        // Try to get the command name from data
        if (isset($payload['data']['commandName'])) {
            return $payload['data']['commandName'];
        }

        // Try to unserialize the command if it's a serialized object
        if (isset($payload['data']['command'])) {
            $command = $payload['data']['command'];
            // Check if it's a serialized object (starts with O: or a:)
            if (is_string($command) && preg_match('/^[Oa]:\d+:/', $command)) {
                try {
                    $unserialized = unserialize($command);
                    if (is_object($unserialized)) {
                        return get_class($unserialized);
                    }
                } catch (\Throwable $e) {
                    // Failed to unserialize, continue
                }
            }
        }

        // Fallback to the job class
        if (isset($payload['job'])) {
            return $payload['job'];
        }

        return 'unknown';
    }
}
