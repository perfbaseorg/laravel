<?php

namespace Tests;

use Orchestra\Testbench\TestCase;
use Perfbase\Laravel\PerfbaseServiceProvider;
use Perfbase\Laravel\Profiling\UniversalProfiler;
use Perfbase\SDK\Config;
use Perfbase\SDK\Extension\ExtensionInterface;
use Perfbase\SDK\Perfbase as PerfbaseClient;
use Mockery;

class UniversalProfilerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PerfbaseServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock the extension interface
        $mockExtension = Mockery::mock(ExtensionInterface::class);
        $mockExtension->shouldReceive('isAvailable')->andReturn(true);
        $mockExtension->shouldReceive('startSpan')->andReturn();
        $mockExtension->shouldReceive('stopSpan')->andReturn();
        $mockExtension->shouldReceive('getSpanData')->andReturn('{}');
        $mockExtension->shouldReceive('reset')->andReturn();
        
        $this->app->instance(ExtensionInterface::class, $mockExtension);
        
        // Set up basic config
        config([
            'perfbase' => [
                'enabled' => true,
                'api_key' => 'test-key',
                'flags' => 0,
                'sample_rate' => 1.0,
                'sending' => [
                    'proxy' => null,
                    'timeout' => 5,
                ],
            ]
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testConstructorWithBasicType()
    {
        $profiler = new UniversalProfiler('test');
        
        $this->assertInstanceOf(UniversalProfiler::class, $profiler);
    }

    public function testConstructorWithContext()
    {
        $context = [
            'user_id' => 123,
            'action' => 'test_action',
            'metadata' => ['key' => 'value']
        ];
        
        $profiler = new UniversalProfiler('test', $context);
        
        $this->assertEquals($context, $profiler->getContext());
    }

    public function testConstructorWithCustomCallback()
    {
        $callback = function($context) {
            return $context['should_profile'] ?? false;
        };
        
        $context = ['should_profile' => true];
        $profiler = new UniversalProfiler('test', $context, $callback);
        
        // Access the protected shouldProfile method
        $reflection = new \ReflectionClass($profiler);
        $method = $reflection->getMethod('shouldProfile');
        $method->setAccessible(true);
        
        $this->assertTrue($method->invoke($profiler));
    }

    public function testConstructorWithCustomCallbackFalse()
    {
        $callback = function($context) {
            return $context['should_profile'] ?? false;
        };
        
        $context = ['should_profile' => false];
        $profiler = new UniversalProfiler('test', $context, $callback);
        
        // Access the protected shouldProfile method
        $reflection = new \ReflectionClass($profiler);
        $method = $reflection->getMethod('shouldProfile');
        $method->setAccessible(true);
        
        $this->assertFalse($method->invoke($profiler));
    }

    public function testShouldProfileWithDefaultBehavior()
    {
        config(['perfbase.profile.test' => true]);
        
        $profiler = new UniversalProfiler('test');
        
        // Access the protected shouldProfile method
        $reflection = new \ReflectionClass($profiler);
        $method = $reflection->getMethod('shouldProfile');
        $method->setAccessible(true);
        
        $this->assertTrue($method->invoke($profiler));
    }

    public function testShouldProfileWithDefaultBehaviorFalse()
    {
        config(['perfbase.profile.test' => false]);
        
        $profiler = new UniversalProfiler('test');
        
        // Access the protected shouldProfile method
        $reflection = new \ReflectionClass($profiler);
        $method = $reflection->getMethod('shouldProfile');
        $method->setAccessible(true);
        
        $this->assertFalse($method->invoke($profiler));
    }

    public function testShouldProfileWithMissingConfig()
    {
        // Don't set any config for this type
        $profiler = new UniversalProfiler('unknown-type');
        
        // Access the protected shouldProfile method
        $reflection = new \ReflectionClass($profiler);
        $method = $reflection->getMethod('shouldProfile');
        $method->setAccessible(true);
        
        // Should default to true
        $this->assertTrue($method->invoke($profiler));
    }

    public function testGetContext()
    {
        $context = [
            'user_id' => 123,
            'action' => 'test_action'
        ];
        
        $profiler = new UniversalProfiler('test', $context);
        
        $this->assertEquals($context, $profiler->getContext());
    }

    public function testGetContextEmpty()
    {
        $profiler = new UniversalProfiler('test');
        
        $this->assertEquals([], $profiler->getContext());
    }

    public function testAddContext()
    {
        $initialContext = ['user_id' => 123];
        $profiler = new UniversalProfiler('test', $initialContext);
        
        $additionalContext = ['action' => 'test_action', 'metadata' => ['key' => 'value']];
        $profiler->addContext($additionalContext);
        
        $expectedContext = array_merge($initialContext, $additionalContext);
        $this->assertEquals($expectedContext, $profiler->getContext());
    }

    public function testAddContextOverwrite()
    {
        $initialContext = ['user_id' => 123, 'action' => 'initial'];
        $profiler = new UniversalProfiler('test', $initialContext);
        
        $additionalContext = ['action' => 'updated', 'new_key' => 'new_value'];
        $profiler->addContext($additionalContext);
        
        $expectedContext = ['user_id' => 123, 'action' => 'updated', 'new_key' => 'new_value'];
        $this->assertEquals($expectedContext, $profiler->getContext());
    }

    public function testAddContextEmpty()
    {
        $initialContext = ['user_id' => 123];
        $profiler = new UniversalProfiler('test', $initialContext);
        
        $profiler->addContext([]);
        
        $this->assertEquals($initialContext, $profiler->getContext());
    }

    public function testContextSetsAttributes()
    {
        $context = [
            'user_id' => 123,
            'action' => 'test_action'
        ];
        
        $profiler = new UniversalProfiler('test', $context);
        
        // Access the protected attributes property
        $reflection = new \ReflectionClass($profiler);
        $property = $reflection->getProperty('attributes');
        $property->setAccessible(true);
        
        $attributes = $property->getValue($profiler);
        
        // Context should be set as attributes
        $this->assertEquals(123, $attributes['user_id']);
        $this->assertEquals('test_action', $attributes['action']);
    }

    public function testAddContextUpdatesAttributes()
    {
        $profiler = new UniversalProfiler('test');
        
        $context = ['user_id' => 123];
        $profiler->addContext($context);
        
        // Access the protected attributes property
        $reflection = new \ReflectionClass($profiler);
        $property = $reflection->getProperty('attributes');
        $property->setAccessible(true);
        
        $attributes = $property->getValue($profiler);
        
        // New context should be added to attributes
        $this->assertEquals(123, $attributes['user_id']);
    }

    public function testCustomCallbackWithComplexLogic()
    {
        $callback = function($context) {
            // Complex logic: profile only if user is admin and action is important
            return ($context['user_role'] ?? '') === 'admin' && 
                   in_array($context['action'] ?? '', ['create', 'update', 'delete']);
        };
        
        // Test admin with important action
        $context = ['user_role' => 'admin', 'action' => 'create'];
        $profiler = new UniversalProfiler('test', $context, $callback);
        
        $reflection = new \ReflectionClass($profiler);
        $method = $reflection->getMethod('shouldProfile');
        $method->setAccessible(true);
        
        $this->assertTrue($method->invoke($profiler));
        
        // Test admin with unimportant action
        $context = ['user_role' => 'admin', 'action' => 'view'];
        $profiler = new UniversalProfiler('test', $context, $callback);
        
        $this->assertFalse($method->invoke($profiler));
        
        // Test non-admin with important action
        $context = ['user_role' => 'user', 'action' => 'create'];
        $profiler = new UniversalProfiler('test', $context, $callback);
        
        $this->assertFalse($method->invoke($profiler));
    }

    public function testCallbackReceivesCorrectContext()
    {
        $receivedContext = null;
        $callback = function($context) use (&$receivedContext) {
            $receivedContext = $context;
            return true;
        };
        
        $originalContext = ['user_id' => 123, 'action' => 'test'];
        $profiler = new UniversalProfiler('test', $originalContext, $callback);
        
        // Trigger shouldProfile
        $reflection = new \ReflectionClass($profiler);
        $method = $reflection->getMethod('shouldProfile');
        $method->setAccessible(true);
        $method->invoke($profiler);
        
        $this->assertEquals($originalContext, $receivedContext);
    }

    public function testCallbackReceivesUpdatedContext()
    {
        $receivedContext = null;
        $callback = function($context) use (&$receivedContext) {
            $receivedContext = $context;
            return true;
        };
        
        $profiler = new UniversalProfiler('test', ['initial' => 'value'], $callback);
        $profiler->addContext(['added' => 'value']);
        
        // Trigger shouldProfile
        $reflection = new \ReflectionClass($profiler);
        $method = $reflection->getMethod('shouldProfile');
        $method->setAccessible(true);
        $method->invoke($profiler);
        
        $expectedContext = ['initial' => 'value', 'added' => 'value'];
        $this->assertEquals($expectedContext, $receivedContext);
    }

    public function testInheritsFromAbstractProfiler()
    {
        $profiler = new UniversalProfiler('test');
        
        $this->assertInstanceOf(\Perfbase\Laravel\Profiling\AbstractProfiler::class, $profiler);
    }

    public function testCanStartAndStopProfiling()
    {
        $profiler = new UniversalProfiler('test');
        
        // These methods should be available from AbstractProfiler
        $this->assertTrue(method_exists($profiler, 'startProfiling'));
        $this->assertTrue(method_exists($profiler, 'stopProfiling'));
    }

    public function testWorksWithDifferentTypes()
    {
        $httpProfiler = new UniversalProfiler('http.GET./api/users');
        $queueProfiler = new UniversalProfiler('queue.ProcessPodcast');
        $consoleProfiler = new UniversalProfiler('console.migrate');
        
        $this->assertInstanceOf(UniversalProfiler::class, $httpProfiler);
        $this->assertInstanceOf(UniversalProfiler::class, $queueProfiler);
        $this->assertInstanceOf(UniversalProfiler::class, $consoleProfiler);
    }

    public function testHandlesNullCallback()
    {
        $profiler = new UniversalProfiler('test', [], null);
        
        config(['perfbase.profile.test' => true]);
        
        // Access the protected shouldProfile method
        $reflection = new \ReflectionClass($profiler);
        $method = $reflection->getMethod('shouldProfile');
        $method->setAccessible(true);
        
        // Should fall back to default behavior
        $this->assertTrue($method->invoke($profiler));
    }

    public function testHandlesCallbackException()
    {
        $callback = function($context) {
            throw new \Exception('Callback error');
        };
        
        $profiler = new UniversalProfiler('test', [], $callback);
        
        // Access the protected shouldProfile method
        $reflection = new \ReflectionClass($profiler);
        $method = $reflection->getMethod('shouldProfile');
        $method->setAccessible(true);
        
        // Should handle exception gracefully
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Callback error');
        $method->invoke($profiler);
    }
}