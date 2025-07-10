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
use Perfbase\Laravel\Profiling\UniversalProfiler;
use Perfbase\Laravel\Support\PerfbaseConfig;
use Perfbase\Laravel\Support\PerfbaseErrorHandling;
use Perfbase\Laravel\Support\SpanNaming;
use Perfbase\SDK\Config;
use Perfbase\SDK\Config as SdkConfig;
use Perfbase\SDK\Perfbase;
use Perfbase\SDK\Perfbase as PerfbaseClient;
use Perfbase\SDK\Extension\ExtensionInterface;

/**
 * Class PerfbaseServiceProvider
 */
class PerfbaseServiceProvider extends ServiceProvider
{
    use PerfbaseErrorHandling;

    /**
     * Unified span storage with unique IDs
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
            
            // Register commands
            $this->commands([
                \Perfbase\Laravel\Commands\PerfbaseClearCommand::class,
                \Perfbase\Laravel\Commands\PerfbaseSyncCommand::class,
            ]);
        }

        if (PerfbaseConfig::enabled()) {
            $this->registerEventListeners();
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
            /** @var SdkConfig $config */
            $config = $app->make(SdkConfig::class);

            // Check if we have a mocked extension in the container (for testing)
            $extension = $app->bound(ExtensionInterface::class) 
                ? $app->make(ExtensionInterface::class) 
                : null;

            // Start a new perfbase instance
            return new PerfbaseClient($config, $extension);
        });
    }

    /**
     * Register unified event listeners for profiling
     *
     * @return void
     */
    private function registerEventListeners(): void
    {
        $this->registerEventPair(
            JobProcessing::class,
            JobProcessed::class,
            JobExceptionOccurred::class,
            fn($event) => $this->createQueueProfiler($event)
        );

        $this->registerEventPair(
            CommandStarting::class,
            CommandFinished::class,
            null,
            fn($event) => $this->createConsoleProfiler($event)
        );
    }

    /**
     * Register a pair of start/stop/error events with unified handling
     *
     * @param string $startEvent
     * @param string $stopEvent
     * @param string|null $errorEvent
     * @param callable $profilerFactory
     * @return void
     */
    private function registerEventPair(string $startEvent, string $stopEvent, ?string $errorEvent, callable $profilerFactory): void
    {
        // Start event handler
        Event::listen($startEvent, function ($event) use ($profilerFactory) {
            try {
                $spanId = uniqid('span_', true);
                $profiler = $profilerFactory($event);
                
                if ($profiler) {
                    $this->spans[$spanId] = $profiler;
                    $this->storeSpanId($event, $spanId);
                    $profiler->startProfiling();
                }
            } catch (\Throwable $e) {
                $this->handleProfilingError($e, 'event_start');
            }
        });

        // Stop event handler
        Event::listen($stopEvent, function ($event) {
            try {
                $spanId = $this->getSpanId($event);
                if ($spanId && isset($this->spans[$spanId])) {
                    $profiler = $this->spans[$spanId];
                    $this->handleEventData($event, $profiler);
                    $profiler->stopProfiling();
                    unset($this->spans[$spanId]);
                }
            } catch (\Throwable $e) {
                $this->handleProfilingError($e, 'event_stop');
            }
        });

        // Error event handler (if provided)
        if ($errorEvent) {
            Event::listen($errorEvent, function ($event) {
                try {
                    $spanId = $this->getSpanId($event);
                    if ($spanId && isset($this->spans[$spanId])) {
                        $profiler = $this->spans[$spanId];
                        $this->handleEventData($event, $profiler);
                        $profiler->stopProfiling();
                        unset($this->spans[$spanId]);
                    }
                } catch (\Throwable $e) {
                    $this->handleProfilingError($e, 'event_error');
                }
            });
        }
    }

    /**
     * Create a queue profiler from event
     *
     * @param JobProcessing $event
     * @return UniversalProfiler
     */
    private function createQueueProfiler(JobProcessing $event): UniversalProfiler
    {
        $jobName = $this->getCommandFromJob($event->job);
        $spanName = SpanNaming::forQueue($jobName);
        
        return new UniversalProfiler($spanName, [
            'job_name' => $jobName,
            'queue' => $event->job->getQueue(),
            'connection' => $event->connectionName,
        ]);
    }

    /**
     * Create a console profiler from event
     *
     * @param CommandStarting $event
     * @return UniversalProfiler|null
     */
    private function createConsoleProfiler(CommandStarting $event): ?UniversalProfiler
    {
        if (!$event->command || !$event->input || !$event->output) {
            return null;
        }

        $spanName = SpanNaming::forConsole($event->command);

        return new UniversalProfiler($spanName, [
            'command' => $event->command,
            'arguments' => $event->input->getArguments(),
            'options' => $event->input->getOptions(),
        ]);
    }

    /**
     * Store span ID for later retrieval
     *
     * @param mixed $event
     * @param string $spanId
     * @return void
     */
    private function storeSpanId($event, string $spanId): void
    {
        if ($event instanceof JobProcessing) {
            // Queue job - store in payload
            $payload = $event->job->payload();
            $payload['perfbase_span_id'] = $spanId;
        } else {
            // Console command - store as property
            $event->perfbaseSpanId = $spanId;
        }
    }

    /**
     * Get span ID from event
     *
     * @param mixed $event
     * @return string|null
     */
    private function getSpanId($event): ?string
    {
        if ($event instanceof JobProcessed || $event instanceof JobExceptionOccurred) {
            return $event->job->payload()['perfbase_span_id'] ?? null;
        }
        
        return $event->perfbaseSpanId ?? null;
    }

    /**
     * Handle event-specific data
     *
     * @param mixed $event
     * @param AbstractProfiler $profiler
     * @return void
     */
    private function handleEventData($event, AbstractProfiler $profiler): void
    {
        if ($event instanceof JobExceptionOccurred) {
            $profiler->setException($event->exception->getMessage());
        } elseif ($event instanceof CommandFinished) {
            $profiler->setExitCode($event->exitCode);
        }
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
