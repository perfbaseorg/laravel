<?php

namespace Tests;

require_once __DIR__ . '/TestHelpers.php';

use Orchestra\Testbench\TestCase;
use Perfbase\Laravel\Profiling\AbstractProfiler;
use Perfbase\Laravel\PerfbaseServiceProvider;
use Perfbase\SDK\Config;
use Perfbase\SDK\Perfbase as PerfbaseClient;
use Perfbase\SDK\SubmitResult;
use RuntimeException;
use ReflectionClass;
use Mockery;

// Concrete implementation for testing
class ConcreteProfiler extends AbstractProfiler
{
    protected function shouldProfile(): bool
    {
        return true;
    }
}

class ConditionalSubmitProfiler extends AbstractProfiler
{
    private bool $shouldSubmitTrace;

    public function __construct(string $spanName, bool $shouldSubmitTrace)
    {
        $this->shouldSubmitTrace = $shouldSubmitTrace;
        parent::__construct($spanName);
    }

    protected function shouldProfile(): bool
    {
        return true;
    }

    protected function shouldSubmitTrace(): bool
    {
        return $this->shouldSubmitTrace;
    }
}

class AbstractProfilerTest extends TestCase
{
    private ConcreteProfiler $profiler;
    private ReflectionClass $reflection;
    private $perfbaseClient;
    private ?string $originalServerAddr = null;

    protected function getPackageProviders($app): array
    {
        return [PerfbaseServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServerAddr = $_SERVER['SERVER_ADDR'] ?? null;
        $_SERVER['SERVER_ADDR'] = '10.31.32.33';
        
        // Mock the Perfbase config and client
        $config = Mockery::mock(Config::class);
        $this->perfbaseClient = Mockery::mock(PerfbaseClient::class);
        $this->perfbaseClient->allows('isExtensionAvailable')->andReturns(true);
        $this->perfbaseClient->allows('startTraceSpan')->andReturns(true);
        $this->perfbaseClient->allows('stopTraceSpan')->andReturns(true);
        $this->perfbaseClient->allows('setAttribute')->andReturns(true);
        $this->perfbaseClient->allows('submitTrace')->andReturns(SubmitResult::success());
        $this->perfbaseClient->allows('getTraceData')->andReturns('serialized_trace_data');
        $this->perfbaseClient->allows('reset')->andReturns(true);
        
        $this->app->instance(Config::class, $config);
        $this->app->instance(PerfbaseClient::class, $this->perfbaseClient);

        // Set up basic config
        config([
            'perfbase' => [
                'enabled' => true,
                'api_key' => 'test-key',
                'sample_rate' => 1.0,
            ],
            'app' => [
                'env' => 'testing',
                'version' => '1.0.0'
            ]
        ]);
        
        $this->profiler = new ConcreteProfiler('test_span');
        $this->reflection = new ReflectionClass(AbstractProfiler::class);
    }

    protected function tearDown(): void
    {
        if ($this->originalServerAddr === null) {
            unset($_SERVER['SERVER_ADDR']);
        } else {
            $_SERVER['SERVER_ADDR'] = $this->originalServerAddr;
        }

        Mockery::close();
        parent::tearDown();
    }

    public function testConstructor()
    {
        $this->assertEquals('test_span', $this->getPrivateProperty('spanName'));
        $this->assertInstanceOf(PerfbaseClient::class, $this->getPrivateProperty('perfbase'));
    }

    public function testSetAttribute()
    {
        $this->profiler->setAttribute('key1', 'value1');
        $this->profiler->setAttribute('key2', 'value2');
        
        $attributes = $this->getPrivateProperty('attributes');
        $this->assertEquals('value1', $attributes['key1']);
        $this->assertEquals('value2', $attributes['key2']);
    }

    public function testSetAttributes()
    {
        $attributes = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3'
        ];
        
        $this->profiler->setAttributes($attributes);
        
        $storedAttributes = $this->getPrivateProperty('attributes');
        foreach ($attributes as $key => $value) {
            $this->assertEquals($value, $storedAttributes[$key]);
        }
    }

    public function testPassesSampleRateCheckWithValidRate()
    {
        // Test with 100% sample rate
        config(['perfbase.sample_rate' => 1.0]);
        $this->assertTrue($this->callPrivateMethod('passesSampleRateCheck'));
        
        // Test with 0% sample rate
        config(['perfbase.sample_rate' => 0.0]);
        $this->assertFalse($this->callPrivateMethod('passesSampleRateCheck'));
    }

    public function testZeroSampleRateNeverSamplesAndDoesNotConsumeRandomness()
    {
        foreach ([0, 0.0, '0', '0.0'] as $rate) {
            config(['perfbase.sample_rate' => $rate]);

            mt_srand(1234);
            $expectedNext = mt_rand();
            mt_srand(1234);

            $this->assertFalse($this->callPrivateMethod('passesSampleRateCheck'));
            // The RNG must not have been touched: 0.0 is a hard "never".
            $this->assertSame($expectedNext, mt_rand());
        }

        mt_srand();
    }

    public function testFullSampleRateAlwaysSamplesAndDoesNotConsumeRandomness()
    {
        foreach ([1, 1.0, '1', '1.0'] as $rate) {
            config(['perfbase.sample_rate' => $rate]);

            mt_srand(1234);
            $expectedNext = mt_rand();
            mt_srand(1234);

            $this->assertTrue($this->callPrivateMethod('passesSampleRateCheck'));
            $this->assertSame($expectedNext, mt_rand());
        }

        mt_srand();
    }

    public function testFractionalSampleRateIsHonouredStatistically()
    {
        config(['perfbase.sample_rate' => 0.5]);
        mt_srand(42);

        $sampled = 0;
        for ($i = 0; $i < 2000; $i++) {
            if ($this->callPrivateMethod('passesSampleRateCheck')) {
                $sampled++;
            }
        }

        mt_srand();

        // Seeded, so deterministic; the bounds just guard against an
        // inverted or always-on comparison.
        $this->assertGreaterThan(800, $sampled);
        $this->assertLessThan(1200, $sampled);
    }

    public function testPassesSampleRateCheckThrowsExceptionWithInvalidRate()
    {
        config(['perfbase.sample_rate' => 1.5]); // Invalid rate > 1
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Configured perfbase `sample_rate` must be a decimal between 0.0 and 1.0.');
        
        $this->callPrivateMethod('passesSampleRateCheck');
    }

    public function testPassesSampleRateCheckThrowsExceptionWithNegativeRate()
    {
        config(['perfbase.sample_rate' => -0.5]);
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Configured perfbase `sample_rate` must be a decimal between 0.0 and 1.0.');
        
        $this->callPrivateMethod('passesSampleRateCheck');
    }

    public function testPassesSampleRateCheckThrowsExceptionWithNonNumeric()
    {
        config(['perfbase.sample_rate' => 'invalid']);
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Configured perfbase `sample_rate` must be a decimal between 0.0 and 1.0.');
        
        $this->callPrivateMethod('passesSampleRateCheck');
    }

    public function testSetDefaultAttributes()
    {
        $this->callPrivateMethod('setDefaultAttributes');

        $attributes = $this->getPrivateProperty('attributes');

        $this->assertArrayHasKey('hostname', $attributes);
        $this->assertArrayHasKey('environment', $attributes);
        $this->assertArrayHasKey('app_version', $attributes);
        $this->assertArrayHasKey('php_version', $attributes);
        $this->assertSame('10.31.32.33', $attributes['user_ip']);

        // user_agent is HTTP-only, not in base defaults.
        $this->assertArrayNotHasKey('user_agent', $attributes);

        $this->assertEquals('testing', $attributes['environment']);
        $this->assertEquals('1.0.0', $attributes['app_version']);
        $this->assertEquals(phpversion(), $attributes['php_version']);
    }

    public function testStartProfilingWithSampleRateZero()
    {
        config(['perfbase.sample_rate' => 0.0]);
        
        $this->profiler->startProfiling();
        
        // Should not start profiling
        $this->perfbaseClient->shouldNotHaveReceived('startTraceSpan');
        $this->assertTrue(true); // Explicit assertion
    }

    public function testStartProfilingWithShouldProfileFalse()
    {
        // Create a profiler that returns false for shouldProfile
        $profiler = new class('test') extends AbstractProfiler {
            protected function shouldProfile(): bool
            {
                return false;
            }
        };
        
        $profiler->startProfiling();
        
        // Should not start profiling
        $this->perfbaseClient->shouldNotHaveReceived('startTraceSpan');
        $this->assertTrue(true); // Explicit assertion
    }

    public function testStopProfilingSubmitsTrace()
    {
        // Just test that the method can be called without errors
        $this->profiler->startProfiling();
        $this->profiler->setAttribute('test', 'value');
        $this->profiler->stopProfiling();

        $this->assertTrue(true);
    }

    public function testStopProfilingWhenSpanNotStarted()
    {
        // Create a client mock where stopTraceSpan returns false
        $client = Mockery::mock(PerfbaseClient::class);
        $client->allows('isExtensionAvailable')->andReturns(true);
        $client->allows('startTraceSpan');
        $client->shouldNotReceive('stopTraceSpan');
        $client->shouldNotReceive('setAttribute');
        $client->allows('reset');
        // submitTrace should NOT be called
        $client->shouldNotReceive('submitTrace');

        $this->app->instance(PerfbaseClient::class, $client);

        $profiler = new ConcreteProfiler('not_started');
        $profiler->setAttribute('late_attribute', 'must_not_leak');
        $profiler->stopProfiling();

        $this->assertTrue(true);
    }

    public function testStopProfilingAfterSampledOutStartDoesNotWriteAttributes(): void
    {
        config(['perfbase.sample_rate' => 0.0]);

        $client = Mockery::mock(PerfbaseClient::class);
        $client->allows('isExtensionAvailable')->andReturns(true);
        $client->shouldNotReceive('startTraceSpan');
        $client->shouldNotReceive('stopTraceSpan');
        $client->shouldNotReceive('setAttribute');
        $client->shouldNotReceive('submitTrace');
        $client->allows('reset');

        $this->app->instance(PerfbaseClient::class, $client);

        $profiler = new ConcreteProfiler('sampled_out');
        $profiler->startProfiling();
        $profiler->setAttribute('late_attribute', 'must_not_leak');
        $profiler->stopProfiling();

        $this->assertTrue(true);
    }

    public function testStopProfilingResetsWithoutSubmittingWhenTraceShouldBeDropped(): void
    {
        $client = Mockery::mock(PerfbaseClient::class);
        $client->allows('isExtensionAvailable')->andReturns(true);
        $client->allows('startTraceSpan');
        $client->allows('stopTraceSpan')->andReturns(true);
        $client->allows('setAttribute');
        $client->shouldNotReceive('submitTrace');
        $client->shouldReceive('reset')->once();

        $this->app->instance(PerfbaseClient::class, $client);

        $profiler = new ConditionalSubmitProfiler('dropped_span', false);
        $profiler->startProfiling();
        $profiler->setAttribute('http_status_code', '404');
        $profiler->stopProfiling();

        $this->assertTrue(true);
    }

    public function testStopProfilingLogsOnSubmitFailure()
    {
        // Override mock to return failure
        $failClient = Mockery::mock(PerfbaseClient::class);
        $failClient->allows('isExtensionAvailable')->andReturns(true);
        $failClient->allows('startTraceSpan');
        $failClient->allows('stopTraceSpan')->andReturns(true);
        $failClient->allows('setAttribute');
        $failClient->allows('submitTrace')->andReturns(
            SubmitResult::retryableFailure(503, 'Service Unavailable')
        );
        $failClient->allows('reset');

        $this->app->instance(PerfbaseClient::class, $failClient);

        config(['perfbase.debug' => false, 'perfbase.log_errors' => false]);

        $profiler = new ConcreteProfiler('fail_span');
        $profiler->startProfiling();
        // Should not throw even though submission failed
        $profiler->stopProfiling();

        $this->assertTrue(true);
    }

    public function testStopProfilingThrowsInDebugModeOnFailure()
    {
        $failClient = Mockery::mock(PerfbaseClient::class);
        $failClient->allows('isExtensionAvailable')->andReturns(true);
        $failClient->allows('startTraceSpan');
        $failClient->allows('stopTraceSpan')->andReturns(true);
        $failClient->allows('setAttribute');
        $failClient->allows('submitTrace')->andReturns(
            SubmitResult::permanentFailure(401, 'Unauthorized')
        );
        $failClient->allows('reset');

        $this->app->instance(PerfbaseClient::class, $failClient);

        config(['perfbase.debug' => true]);
        \Perfbase\Laravel\Support\PerfbaseConfig::clearCache();

        $profiler = new ConcreteProfiler('debug_span');
        $profiler->startProfiling();

        $this->expectException(\Perfbase\SDK\Exception\PerfbaseException::class);
        $this->expectExceptionMessage('Trace submission failed');

        $profiler->stopProfiling();
    }

    private function callPrivateMethod(string $methodName, array $args = [])
    {
        $method = $this->reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->profiler, $args);
    }

    private function getPrivateProperty(string $propertyName)
    {
        $property = $this->reflection->getProperty($propertyName);
        $property->setAccessible(true);
        return $property->getValue($this->profiler);
    }
}
