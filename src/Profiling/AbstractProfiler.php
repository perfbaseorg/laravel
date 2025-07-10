<?php

namespace Perfbase\Laravel\Profiling;

use Illuminate\Contracts\Container\BindingResolutionException;
use JsonException;
use Perfbase\Laravel\Caching\CacheStrategyFactory;
use Perfbase\SDK\Exception\PerfbaseException;
use Perfbase\SDK\Exception\PerfbaseExtensionException;
use Perfbase\SDK\Exception\PerfbaseInvalidSpanException;
use Perfbase\SDK\Perfbase as PerfbaseClient;
use Perfbase\SDK\Utils\EnvironmentUtils;
use RuntimeException;

abstract class AbstractProfiler
{
    /** @var PerfbaseClient */
    protected PerfbaseClient $perfbase;

    /** @var array<string, string> */
    protected array $attributes = [];

    /** @var string */
    protected string $spanName;

    /**
     * @throws BindingResolutionException
     */
    public function __construct(string $spanName)
    {
        $this->spanName = $spanName;
        $this->perfbase = $this->getPerfbaseClient();
    }

    /**
     * Get the Perfbase client instance
     *
     * @return PerfbaseClient
     * @throws BindingResolutionException
     */
    private function getPerfbaseClient(): PerfbaseClient
    {
        $client = app()->make(PerfbaseClient::class);

        if (!$client instanceof PerfbaseClient) {
            throw new RuntimeException('Perfbase client is not properly configured.');
        }

        return $client;
    }

    /**
     * Start profiling with the given context
     *
     * @throws JsonException
     * @throws PerfbaseExtensionException
     * @throws PerfbaseInvalidSpanException
     * @throws PerfbaseException
     */
    public function startProfiling(): void
    {
        // Check if profiling should occur
        if (!$this->passesSampleRateCheck() || !$this->shouldProfile()) {
            return;
        }

        $this->perfbase->startTraceSpan($this->spanName);
        $this->setDefaultAttributes();
    }

    /**
     * Stop profiling and handle the trace data
     *
     * @throws PerfbaseException
     */
    public function stopProfiling(): void
    {
        // Apply attributes
        foreach ($this->attributes as $key => $value) {
            $this->perfbase->setAttribute($key, $value);
        }

        if (!$this->perfbase->stopTraceSpan($this->spanName)) {
            return;
        }

        // Determine if we should send now or cache
        $sendingMode = config('perfbase.sending.mode');
        $shouldSendNow = $sendingMode === 'sync';

        if (!in_array($sendingMode, ['sync', 'database', 'file'], true)) {
            throw new RuntimeException('Invalid sending mode specified in the configuration.');
        }

        if ($shouldSendNow) {
            $this->perfbase->submitTrace();
        } else {
            $cache = CacheStrategyFactory::make();
            $cache->store([
                'data' => $this->perfbase->getTraceData(),
                'created_at' => now()->toDateTimeString(),
            ]);
        }
    }

    /**
     * Set an attribute for the current trace
     */
    public function setAttribute(string $key, string $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Set multiple attributes at once
     *
     * @param array<string, string> $attributes
     */
    public function setAttributes(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
    }

    /**
     * Set default attributes that should be included in every trace
     *
     * @throws PerfbaseException
     */
    protected function setDefaultAttributes(): void
    {
        $environment = config('app.env', '');
        if (!is_string($environment)) {
            throw new PerfbaseException('Config perfbase `app.env` must be a string.');
        }

        $appVersion = config('app.version', '');
        if (!is_string($appVersion)) {
            throw new PerfbaseException('Config `app.version` must be a string.');
        }

        $hostname = gethostname();
        if (!is_string($hostname)) {
            $hostname = '';
        }

        $phpVersion = phpversion();
        if (!is_string($phpVersion)) {
            $phpVersion = '';
        }

        $this->setAttributes([
            'hostname' => $hostname,
            'environment' => $environment,
            'app_version' => $appVersion,
            'php_version' => $phpVersion,
            'user_ip' => EnvironmentUtils::getUserIp() ?? '',
            'user_agent' => EnvironmentUtils::getUserUserAgent() ?? '',
        ]);
    }

    /**
     * Check if the sample rate is met for the current trace
     */
    protected function passesSampleRateCheck(): bool
    {
        // Grab the sample rate from the configuration
        $sampleRate = config('perfbase.sample_rate');

        // Check if the sample rate is a valid decimal between 0.0 and 1.0
        if (!is_numeric($sampleRate) || $sampleRate < 0 || $sampleRate > 1) {
            throw new RuntimeException('Configured perfbase `sample_rate` must be a decimal between 0.0 and 1.0.');
        }

        /**
         * Generate a random decimal between 0.0 and 1.0
         * @var double $randomDecimal
         */
        $randomDecimal = mt_rand() / mt_getrandmax();

        // Check if the random decimal is less than or equal to the sample rate
        return $randomDecimal <= $sampleRate;
    }

    /**
     * Set exception information for the current trace
     *
     * @param string $message
     */
    public function setException(string $message): void
    {
        $this->setAttribute('exception', $message);
    }

    /**
     * Set exit code for the current trace
     *
     * @param int $code
     */
    public function setExitCode(int $code): void
    {
        $this->setAttribute('exit_code', (string)$code);
    }

    /**
     * Determine if the current context should be profiled
     *
     * @return bool
     */
    abstract protected function shouldProfile(): bool;
}
