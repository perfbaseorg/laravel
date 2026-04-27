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
use Perfbase\Laravel\Lifecycle\ConsoleTraceLifecycle;
use Perfbase\Laravel\Lifecycle\QueueTraceLifecycle;
use Perfbase\Laravel\Profiling\AbstractProfiler;
use Perfbase\Laravel\Support\PerfbaseConfig;
use Perfbase\Laravel\Support\PerfbaseErrorHandling;
use Perfbase\SDK\Config;
use Perfbase\SDK\Config as SdkConfig;
use Perfbase\SDK\Perfbase;
use Perfbase\SDK\Perfbase as PerfbaseClient;
use Perfbase\SDK\Extension\ExtensionInterface;

class PerfbaseServiceProvider extends ServiceProvider
{
    use PerfbaseErrorHandling;

    /**
     * Active profiler instances keyed by span ID.
     * @var array<string, AbstractProfiler>
     */
    private array $spans = [];

    /**
     * Queue job span IDs keyed by job ID.
     * @var array<string, string>
     */
    private array $queueSpanIds = [];

    /**
     * Console command span IDs keyed by command name.
     * @var array<string, string>
     */
    private array $consoleSpanIds = [];

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/perfbase.php' => config_path('perfbase.php'),
            ], 'perfbase-config');
        }

        if (PerfbaseConfig::enabled()) {
            $this->registerEventListeners();
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/perfbase.php', 'perfbase');

        $this->app->bind(Config::class, function (Application $app) {
            /** @var array<string, mixed> $config */
            $config = $app['config'];

            return Config::fromArray([
                'api_key' => $config['perfbase.api_key'],
                'api_url' => $config['perfbase.api_url'] ?? 'https://ingress.perfbase.cloud',
                'flags' => $config['perfbase.flags'],
                'proxy' => $config['perfbase.proxy'],
                'timeout' => $config['perfbase.timeout'],
            ]);
        });

        $this->app->singleton(Perfbase::class, function (Application $app) {
            /** @var SdkConfig $config */
            $config = $app->make(SdkConfig::class);

            $extension = $app->bound(ExtensionInterface::class)
                ? $app->make(ExtensionInterface::class)
                : null;

            return new PerfbaseClient($config, $extension);
        });
    }

    private function registerEventListeners(): void
    {
        // Queue: JobProcessing → start, JobProcessed/JobExceptionOccurred → stop
        $this->registerEventPair(
            JobProcessing::class,
            JobProcessed::class,
            JobExceptionOccurred::class,
            fn($event) => $this->createQueueLifecycle($event)
        );

        // Console: CommandStarting → start, CommandFinished → stop
        $this->registerEventPair(
            CommandStarting::class,
            CommandFinished::class,
            null,
            fn($event) => $this->createConsoleLifecycle($event)
        );
    }

    private function registerEventPair(
        string $startEvent,
        string $stopEvent,
        ?string $errorEvent,
        callable $lifecycleFactory
    ): void {
        Event::listen($startEvent, function ($event) use ($lifecycleFactory) {
            try {
                $spanId = uniqid('span_', true);
                $lifecycle = $lifecycleFactory($event);

                if ($lifecycle) {
                    $this->spans[$spanId] = $lifecycle;
                    $this->storeSpanId($event, $spanId);
                    $lifecycle->startProfiling();
                }
            } catch (\Throwable $e) {
                $this->handleProfilingError($e, 'event_start');
            }
        });

        Event::listen($stopEvent, function ($event) {
            try {
                $spanId = $this->getSpanId($event);
                if ($spanId && isset($this->spans[$spanId])) {
                    $lifecycle = $this->spans[$spanId];
                    $this->handleEventData($event, $lifecycle);
                    $lifecycle->stopProfiling();
                    unset($this->spans[$spanId]);
                }
            } catch (\Throwable $e) {
                $this->handleProfilingError($e, 'event_stop');
            }
        });

        if ($errorEvent) {
            Event::listen($errorEvent, function ($event) {
                try {
                    $spanId = $this->getSpanId($event);
                    if ($spanId && isset($this->spans[$spanId])) {
                        $lifecycle = $this->spans[$spanId];
                        $this->handleEventData($event, $lifecycle);
                        $lifecycle->stopProfiling();
                        unset($this->spans[$spanId]);
                    }
                } catch (\Throwable $e) {
                    $this->handleProfilingError($e, 'event_error');
                }
            });
        }
    }

    private function createQueueLifecycle(JobProcessing $event): QueueTraceLifecycle
    {
        $jobName = $this->getJobDisplayName($event->job);

        return new QueueTraceLifecycle(
            $jobName,
            $event->job->getQueue(),
            $event->connectionName
        );
    }

    private function createConsoleLifecycle(CommandStarting $event): ?ConsoleTraceLifecycle
    {
        if (!$event->command) {
            return null;
        }

        return new ConsoleTraceLifecycle($event->command);
    }

    /** @param JobProcessing|CommandStarting $event */
    private function storeSpanId(object $event, string $spanId): void
    {
        if ($event instanceof JobProcessing) {
            $jobId = $event->job->getJobId() ?? spl_object_hash($event->job);
            $this->queueSpanIds[$jobId] = $spanId;
        } elseif ($event instanceof CommandStarting && $event->command) {
            $this->consoleSpanIds[$event->command] = $spanId;
        }
    }

    /** @param JobProcessed|JobExceptionOccurred|CommandFinished $event */
    private function getSpanId(object $event): ?string
    {
        if ($event instanceof JobProcessed || $event instanceof JobExceptionOccurred) {
            $jobId = $event->job->getJobId() ?? spl_object_hash($event->job);
            $spanId = $this->queueSpanIds[$jobId] ?? null;
            unset($this->queueSpanIds[$jobId]);
            return $spanId;
        }

        if ($event instanceof CommandFinished && $event->command) {
            $spanId = $this->consoleSpanIds[$event->command] ?? null;
            unset($this->consoleSpanIds[$event->command]);
            return $spanId;
        }

        return null;
    }

    /** @param JobProcessed|JobExceptionOccurred|CommandFinished $event */
    private function handleEventData(object $event, AbstractProfiler $lifecycle): void
    {
        if ($event instanceof JobExceptionOccurred) {
            $lifecycle->setException($event->exception->getMessage());
        } elseif ($event instanceof CommandFinished) {
            $lifecycle->setExitCode($event->exitCode);
        }
    }

    private function getJobDisplayName(Job $job): string
    {
        $payload = $job->payload();

        if (isset($payload['displayName'])) {
            return $payload['displayName'];
        }

        if (isset($payload['data']['commandName'])) {
            return $payload['data']['commandName'];
        }

        if (isset($payload['data']['command'])) {
            $command = $payload['data']['command'];
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

        if (isset($payload['job'])) {
            return $payload['job'];
        }

        return 'unknown';
    }
}
