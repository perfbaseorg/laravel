<?php

namespace Perfbase\Laravel\Profiling;

use Illuminate\Contracts\Container\BindingResolutionException;
use Perfbase\Laravel\Support\PerfbaseErrorHandling;
use Perfbase\SDK\Exception\PerfbaseException;
use Perfbase\SDK\Exception\PerfbaseExtensionException;
use Perfbase\SDK\Exception\PerfbaseInvalidSpanException;
use Perfbase\SDK\Perfbase as PerfbaseClient;
use RuntimeException;

abstract class AbstractProfiler
{
    use PerfbaseErrorHandling;

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
     * Stop profiling and submit the trace.
     *
     * On submission failure, the error is logged but not re-thrown
     * so profiling never disrupts the application.
     *
     * @throws PerfbaseException
     */
    public function stopProfiling(): void
    {
        foreach ($this->attributes as $key => $value) {
            $this->perfbase->setAttribute($key, $value);
        }

        if (!$this->perfbase->stopTraceSpan($this->spanName)) {
            return;
        }

        $result = $this->perfbase->submitTrace();

        if (!$result->isSuccess()) {
            $this->handleProfilingError(
                new PerfbaseException(sprintf(
                    'Trace submission failed (%s): %s',
                    $result->getStatus(),
                    $result->getMessage()
                )),
                'submit'
            );
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
     * Set default attributes that should be included in every trace.
     * Subclasses should call parent and add context-specific attributes.
     */
    protected function setDefaultAttributes(): void
    {
        $environment = config('app.env', '');
        $appVersion = config('app.version', '');
        $hostname = gethostname() ?: '';
        $phpVersion = phpversion() ?: '';

        $this->setAttributes([
            'hostname' => $hostname,
            'environment' => $environment,
            'app_version' => $appVersion,
            'php_version' => $phpVersion,
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
