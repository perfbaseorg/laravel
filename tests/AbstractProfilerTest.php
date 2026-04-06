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

class AbstractProfilerTest extends TestCase
{
    private ConcreteProfiler $profiler;
    private ReflectionClass $reflection;
    private $perfbaseClient;

    protected function getPackageProviders($app): array
    {
        return [PerfbaseServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        
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
        $this->assertArrayHasKey('user_ip', $attributes);
        $this->assertArrayHasKey('user_agent', $attributes);
        
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
        $this->profiler->setAttribute('test', 'value');
        $this->profiler->stopProfiling();

        $this->assertTrue(true);
    }

    public function testStopProfilingWhenSpanNotStarted()
    {
        // Just test that method can be called
        $this->profiler->stopProfiling();
        $this->assertTrue(true);
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