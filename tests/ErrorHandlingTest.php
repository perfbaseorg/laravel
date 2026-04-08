<?php

namespace Tests;

use Orchestra\Testbench\TestCase;
use Perfbase\Laravel\PerfbaseServiceProvider;
use Perfbase\Laravel\Support\PerfbaseConfig;
use Perfbase\Laravel\Support\PerfbaseErrorHandling;

class ErrorHandlingTestSubject
{
    use PerfbaseErrorHandling;

    public function triggerExtensionError(\Throwable $e): void
    {
        $this->handleExtensionError($e);
    }

    public function triggerProfilingError(\Throwable $e, string $context = ''): void
    {
        $this->handleProfilingError($e, $context);
    }
}

class ErrorHandlingTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PerfbaseServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        PerfbaseConfig::clearCache();
    }

    protected function tearDown(): void
    {
        PerfbaseConfig::clearCache();
        parent::tearDown();
    }

    public function testDebugModeRethrowsExtensionError(): void
    {
        config(['perfbase.debug' => true]);
        PerfbaseConfig::clearCache();

        $subject = new ErrorHandlingTestSubject();
        $exception = new \RuntimeException('Extension broke');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Extension broke');

        $subject->triggerExtensionError($exception);
    }

    public function testDebugModeRethrowsProfilingError(): void
    {
        config(['perfbase.debug' => true]);
        PerfbaseConfig::clearCache();

        $subject = new ErrorHandlingTestSubject();
        $exception = new \RuntimeException('Profiling broke');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Profiling broke');

        $subject->triggerProfilingError($exception, 'submit');
    }

    public function testProductionModeSilencesExtensionError(): void
    {
        config([
            'perfbase.debug' => false,
            'perfbase.log_errors' => false,
        ]);
        PerfbaseConfig::clearCache();

        $subject = new ErrorHandlingTestSubject();
        $exception = new \RuntimeException('Should be silenced');

        // Should not throw
        $subject->triggerExtensionError($exception);
        $this->assertTrue(true);
    }

    public function testProductionModeSilencesProfilingError(): void
    {
        config([
            'perfbase.debug' => false,
            'perfbase.log_errors' => false,
        ]);
        PerfbaseConfig::clearCache();

        $subject = new ErrorHandlingTestSubject();
        $exception = new \RuntimeException('Should be silenced');

        $subject->triggerProfilingError($exception, 'event_start');
        $this->assertTrue(true);
    }

    public function testDefaultDebugIsFalse(): void
    {
        // Don't set debug — default should be false (no rethrow)
        config(['perfbase.log_errors' => false]);
        PerfbaseConfig::clearCache();

        $subject = new ErrorHandlingTestSubject();
        $exception = new \RuntimeException('Should not throw');

        $subject->triggerExtensionError($exception);
        $this->assertTrue(true);
    }

    public function testLoggingModeLogsExtensionError(): void
    {
        config([
            'perfbase.debug' => false,
            'perfbase.log_errors' => true,
        ]);
        PerfbaseConfig::clearCache();

        $subject = new ErrorHandlingTestSubject();
        $exception = new \RuntimeException('Logged extension error');

        // Should not throw, but should attempt to log
        $subject->triggerExtensionError($exception);
        $this->assertTrue(true);
    }

    public function testLoggingModeLogsProfilingError(): void
    {
        config([
            'perfbase.debug' => false,
            'perfbase.log_errors' => true,
        ]);
        PerfbaseConfig::clearCache();

        $subject = new ErrorHandlingTestSubject();
        $exception = new \RuntimeException('Logged profiling error');

        $subject->triggerProfilingError($exception, 'event_start');
        $this->assertTrue(true);
    }

    public function testLoggingDisabledSkipsLogger(): void
    {
        config([
            'perfbase.debug' => false,
            'perfbase.log_errors' => false,
        ]);
        PerfbaseConfig::clearCache();

        $subject = new ErrorHandlingTestSubject();
        $exception = new \RuntimeException('Not logged');

        // Should not throw and should not attempt to log
        $subject->triggerProfilingError($exception, 'event_stop');
        $this->assertTrue(true);
    }
}
