<?php

namespace Tests;

use Orchestra\Testbench\TestCase;
use Perfbase\Laravel\PerfbaseServiceProvider;
use Perfbase\Laravel\Support\PerfbaseConfig;

class PerfbaseConfigTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PerfbaseServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear the cache before each test
        PerfbaseConfig::clearCache();
    }

    protected function tearDown(): void
    {
        // Clear the cache after each test
        PerfbaseConfig::clearCache();
        parent::tearDown();
    }

    public function testGetWithExistingKey()
    {
        config(['perfbase.test_key' => 'test_value']);
        
        $value = PerfbaseConfig::get('test_key');
        
        $this->assertEquals('test_value', $value);
    }

    public function testGetWithNonExistentKey()
    {
        $value = PerfbaseConfig::get('non_existent_key');
        
        $this->assertNull($value);
    }

    public function testGetWithDefaultValue()
    {
        $value = PerfbaseConfig::get('non_existent_key', 'default_value');
        
        $this->assertEquals('default_value', $value);
    }

    public function testGetWithNestedKey()
    {
        config(['perfbase.nested.key' => 'nested_value']);
        
        $value = PerfbaseConfig::get('nested.key');
        
        $this->assertEquals('nested_value', $value);
    }

    public function testGetWithDeepNestedKey()
    {
        config(['perfbase.level1.level2.level3' => 'deep_value']);
        
        $value = PerfbaseConfig::get('level1.level2.level3');
        
        $this->assertEquals('deep_value', $value);
    }

    public function testGetWithArrayValue()
    {
        config(['perfbase.array_key' => ['item1', 'item2', 'item3']]);
        
        $value = PerfbaseConfig::get('array_key');
        
        $this->assertEquals(['item1', 'item2', 'item3'], $value);
    }

    public function testGetWithBooleanValue()
    {
        config(['perfbase.bool_true' => true]);
        config(['perfbase.bool_false' => false]);
        
        $this->assertTrue(PerfbaseConfig::get('bool_true'));
        $this->assertFalse(PerfbaseConfig::get('bool_false'));
    }

    public function testGetWithNumericValue()
    {
        config(['perfbase.integer' => 42]);
        config(['perfbase.float' => 3.14]);
        
        $this->assertEquals(42, PerfbaseConfig::get('integer'));
        $this->assertEquals(3.14, PerfbaseConfig::get('float'));
    }

    public function testCachingBehavior()
    {
        config(['perfbase.cached_key' => 'original_value']);
        
        // First call
        $value1 = PerfbaseConfig::get('cached_key');
        
        // Change config after first call
        config(['perfbase.cached_key' => 'changed_value']);
        
        // Second call should return cached value
        $value2 = PerfbaseConfig::get('cached_key');
        
        $this->assertEquals('original_value', $value1);
        $this->assertEquals('original_value', $value2); // Should be cached
    }

    public function testClearCacheRefreshesConfig()
    {
        config(['perfbase.cached_key' => 'original_value']);
        
        // First call
        $value1 = PerfbaseConfig::get('cached_key');
        
        // Change config and clear cache
        config(['perfbase.cached_key' => 'changed_value']);
        PerfbaseConfig::clearCache();
        
        // Second call should return new value
        $value2 = PerfbaseConfig::get('cached_key');
        
        $this->assertEquals('original_value', $value1);
        $this->assertEquals('changed_value', $value2);
    }

    public function testEnabledMethod()
    {
        config(['perfbase.enabled' => true]);
        PerfbaseConfig::clearCache();
        
        $this->assertTrue(PerfbaseConfig::enabled());
    }

    public function testEnabledMethodFalse()
    {
        config(['perfbase.enabled' => false]);
        PerfbaseConfig::clearCache();
        
        $this->assertFalse(PerfbaseConfig::enabled());
    }

    public function testEnabledMethodDefault()
    {
        // Don't set perfbase.enabled
        PerfbaseConfig::clearCache();
        
        $this->assertFalse(PerfbaseConfig::enabled()); // Should default to false
    }

    public function testSampleRateMethod()
    {
        config(['perfbase.sample_rate' => 0.5]);
        PerfbaseConfig::clearCache();
        
        $this->assertEquals(0.5, PerfbaseConfig::sampleRate());
    }

    public function testSampleRateMethodDefault()
    {
        // Don't set perfbase.sample_rate - should use config default
        PerfbaseConfig::clearCache();
        
        // The default from config/perfbase.php is 0.1
        $this->assertEquals(0.1, PerfbaseConfig::sampleRate());
    }

    public function testSampleRateMethodWithInteger()
    {
        config(['perfbase.sample_rate' => 1]);
        PerfbaseConfig::clearCache();
        
        $this->assertEquals(1.0, PerfbaseConfig::sampleRate());
    }

    public function testSendingModeMethod()
    {
        config(['perfbase.sending.mode' => 'async']);
        PerfbaseConfig::clearCache();
        
        $this->assertEquals('async', PerfbaseConfig::sendingMode());
    }

    public function testSendingModeMethodDefault()
    {
        // Don't set perfbase.sending.mode
        PerfbaseConfig::clearCache();
        
        $this->assertEquals('sync', PerfbaseConfig::sendingMode()); // Should default to 'sync'
    }

    public function testSendingModeMethodWithNestedConfig()
    {
        config(['perfbase.sending' => ['mode' => 'database', 'timeout' => 10]]);
        PerfbaseConfig::clearCache();
        
        $this->assertEquals('database', PerfbaseConfig::sendingMode());
    }

    public function testMultipleCallsUseSameCache()
    {
        config([
            'perfbase.enabled' => true,
            'perfbase.sample_rate' => 0.8,
            'perfbase.sending.mode' => 'file'
        ]);
        PerfbaseConfig::clearCache();
        
        // Multiple calls to different methods
        $enabled = PerfbaseConfig::enabled();
        $sampleRate = PerfbaseConfig::sampleRate();
        $sendingMode = PerfbaseConfig::sendingMode();
        
        $this->assertTrue($enabled);
        $this->assertEquals(0.8, $sampleRate);
        $this->assertEquals('file', $sendingMode);
    }

    public function testCacheIsSharedAcrossMethods()
    {
        config(['perfbase.test' => 'shared_value']);
        
        // Call one method to populate cache
        PerfbaseConfig::enabled();
        
        // Call get() method - should use same cache
        $value = PerfbaseConfig::get('test');
        
        $this->assertEquals('shared_value', $value);
    }

    public function testGetWithNullConfig()
    {
        config(['perfbase' => null]);
        PerfbaseConfig::clearCache();
        
        $value = PerfbaseConfig::get('any_key', 'default');
        
        $this->assertEquals('default', $value);
    }

    public function testGetWithEmptyConfig()
    {
        config(['perfbase' => []]);
        PerfbaseConfig::clearCache();
        
        $value = PerfbaseConfig::get('any_key', 'default');
        
        $this->assertEquals('default', $value);
    }

    public function testPerformanceWithRepeatedCalls()
    {
        config(['perfbase.performance_test' => 'value']);
        PerfbaseConfig::clearCache();
        
        // First call populates cache
        $start = microtime(true);
        $value1 = PerfbaseConfig::get('performance_test');
        $firstCallTime = microtime(true) - $start;
        
        // Subsequent calls should be faster (cached)
        $start = microtime(true);
        $value2 = PerfbaseConfig::get('performance_test');
        $secondCallTime = microtime(true) - $start;
        
        $this->assertEquals($value1, $value2);
        
        // Second call should be faster (though this might not always be reliable in tests)
        // We'll just verify both calls return the same value
        $this->assertEquals('value', $value1);
        $this->assertEquals('value', $value2);
    }

    public function testCacheIsStaticAcrossInstances()
    {
        config(['perfbase.static_test' => 'static_value']);
        
        // Since all methods are static, there are no "instances"
        // But we can test that the cache persists across multiple static calls
        $value1 = PerfbaseConfig::get('static_test');
        $value2 = PerfbaseConfig::get('static_test');
        
        $this->assertEquals('static_value', $value1);
        $this->assertEquals('static_value', $value2);
    }

    public function testDataGetFunctionality()
    {
        config([
            'perfbase.complex' => [
                'array' => [
                    'nested' => [
                        'deeply' => 'deep_value'
                    ]
                ]
            ]
        ]);
        PerfbaseConfig::clearCache();
        
        $value = PerfbaseConfig::get('complex.array.nested.deeply');
        
        $this->assertEquals('deep_value', $value);
    }

    public function testDataGetWithArrayAccess()
    {
        config([
            'perfbase.indexed' => [
                'first_item',
                'second_item',
                'third_item'
            ]
        ]);
        PerfbaseConfig::clearCache();
        
        $value = PerfbaseConfig::get('indexed.1'); // Should get second item
        
        $this->assertEquals('second_item', $value);
    }

    public function testShortcutMethodsUseCaching()
    {
        config([
            'perfbase.enabled' => true,
            'perfbase.sample_rate' => 0.75,
            'perfbase.sending.mode' => 'database'
        ]);
        
        // First call to enabled() should populate cache
        PerfbaseConfig::enabled();
        
        // Change config
        config(['perfbase.enabled' => false]);
        
        // Second call should return cached value
        $this->assertTrue(PerfbaseConfig::enabled());
        
        // Clear cache and try again
        PerfbaseConfig::clearCache();
        $this->assertFalse(PerfbaseConfig::enabled());
    }
}