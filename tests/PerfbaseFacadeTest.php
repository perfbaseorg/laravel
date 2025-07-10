<?php

namespace Tests;

use Orchestra\Testbench\TestCase;
use Perfbase\Laravel\Facades\Perfbase;
use Perfbase\Laravel\PerfbaseServiceProvider;
use Perfbase\SDK\Config;
use Perfbase\SDK\Extension\ExtensionInterface;
use Perfbase\SDK\Perfbase as PerfbaseClient;
use Mockery;

class PerfbaseFacadeTest extends TestCase
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

    public function testFacadeAccessorReturnsCorrectClass()
    {
        $reflection = new \ReflectionClass(Perfbase::class);
        $method = $reflection->getMethod('getFacadeAccessor');
        $method->setAccessible(true);
        $accessor = $method->invoke(null);
        
        $this->assertEquals(PerfbaseClient::class, $accessor);
    }

    public function testFacadeResolvesToPerfbaseClient()
    {
        $resolved = Perfbase::getFacadeRoot();
        
        $this->assertInstanceOf(PerfbaseClient::class, $resolved);
    }

    public function testFacadeIsSingleton()
    {
        $instance1 = Perfbase::getFacadeRoot();
        $instance2 = Perfbase::getFacadeRoot();
        
        $this->assertSame($instance1, $instance2);
    }

    public function testStartTraceSpanMethod()
    {
        // Mock the Perfbase client
        $mockClient = Mockery::mock(PerfbaseClient::class);
        $mockClient->shouldReceive('startTraceSpan')->once()->with('test-span')->andReturn();
        
        $this->app->instance(PerfbaseClient::class, $mockClient);
        
        Perfbase::startTraceSpan('test-span');
        
        $mockClient->shouldHaveReceived('startTraceSpan')->once();
        $this->assertTrue(true); // Assert that we got this far
    }

    public function testStopTraceSpanMethod()
    {
        // Mock the Perfbase client
        $mockClient = Mockery::mock(PerfbaseClient::class);
        $mockClient->shouldReceive('stopTraceSpan')->once()->with('test-span')->andReturn(true);
        
        $this->app->instance(PerfbaseClient::class, $mockClient);
        
        $result = Perfbase::stopTraceSpan('test-span');
        
        $this->assertTrue($result);
    }

    public function testSubmitTraceMethod()
    {
        // Mock the Perfbase client
        $mockClient = Mockery::mock(PerfbaseClient::class);
        $mockClient->shouldReceive('submitTrace')->once()->andReturn();
        
        $this->app->instance(PerfbaseClient::class, $mockClient);
        
        Perfbase::submitTrace();
        
        $mockClient->shouldHaveReceived('submitTrace')->once();
        $this->assertTrue(true); // Assert that we got this far
    }

    public function testGetTraceDataMethod()
    {
        // Mock the Perfbase client
        $mockClient = Mockery::mock(PerfbaseClient::class);
        $mockClient->shouldReceive('getTraceData')->once()->andReturn('{"trace": "data"}');
        
        $this->app->instance(PerfbaseClient::class, $mockClient);
        
        $result = Perfbase::getTraceData();
        
        $this->assertEquals('{"trace": "data"}', $result);
    }

    public function testResetMethod()
    {
        // Mock the Perfbase client
        $mockClient = Mockery::mock(PerfbaseClient::class);
        $mockClient->shouldReceive('reset')->once()->andReturn();
        
        $this->app->instance(PerfbaseClient::class, $mockClient);
        
        Perfbase::reset();
        
        $mockClient->shouldHaveReceived('reset')->once();
        $this->assertTrue(true); // Assert that we got this far
    }

    public function testIsExtensionAvailableMethod()
    {
        // Mock the Perfbase client
        $mockClient = Mockery::mock(PerfbaseClient::class);
        $mockClient->shouldReceive('isExtensionAvailable')->once()->andReturn(true);
        
        $this->app->instance(PerfbaseClient::class, $mockClient);
        
        $result = Perfbase::isExtensionAvailable();
        
        $this->assertTrue($result);
    }

    public function testSetAttributeMethod()
    {
        // Mock the Perfbase client
        $mockClient = Mockery::mock(PerfbaseClient::class);
        $mockClient->shouldReceive('setAttribute')->once()->with('key', 'value')->andReturn();
        
        $this->app->instance(PerfbaseClient::class, $mockClient);
        
        Perfbase::setAttribute('key', 'value');
        
        $mockClient->shouldHaveReceived('setAttribute')->once();
        $this->assertTrue(true); // Assert that we got this far
    }

    public function testSetFlagsMethod()
    {
        // Mock the Perfbase client
        $mockClient = Mockery::mock(PerfbaseClient::class);
        $mockClient->shouldReceive('setFlags')->once()->with(1)->andReturn();
        
        $this->app->instance(PerfbaseClient::class, $mockClient);
        
        Perfbase::setFlags(1);
        
        $mockClient->shouldHaveReceived('setFlags')->once();
        $this->assertTrue(true); // Assert that we got this far
    }

    public function testFacadeWorksWithRealClient()
    {
        // Test that the facade works with the real client instance
        // This tests the integration without mocking
        
        // Test that the facade resolves to a proper client
        $client = Perfbase::getFacadeRoot();
        $this->assertInstanceOf(PerfbaseClient::class, $client);
        
        // Test that the client has the expected methods
        $this->assertTrue(method_exists($client, 'startTraceSpan'));
        $this->assertTrue(method_exists($client, 'stopTraceSpan'));
        $this->assertTrue(method_exists($client, 'submitTrace'));
        $this->assertTrue(method_exists($client, 'getTraceData'));
        $this->assertTrue(method_exists($client, 'reset'));
        $this->assertTrue(method_exists($client, 'isExtensionAvailable'));
        $this->assertTrue(method_exists($client, 'setAttribute'));
        $this->assertTrue(method_exists($client, 'setFlags'));
    }

    public function testFacadeMethodsWithParameters()
    {
        // Mock the Perfbase client
        $mockClient = Mockery::mock(PerfbaseClient::class);
        $mockClient->shouldReceive('startTraceSpan')->once()->with('span-with-params', ['attr' => 'value'])->andReturn();
        
        $this->app->instance(PerfbaseClient::class, $mockClient);
        
        Perfbase::startTraceSpan('span-with-params', ['attr' => 'value']);
        
        $mockClient->shouldHaveReceived('startTraceSpan')->once();
        $this->assertTrue(true); // Assert that we got this far
    }

    public function testFacadeMethodsWithReturnValues()
    {
        // Mock the Perfbase client
        $mockClient = Mockery::mock(PerfbaseClient::class);
        $mockClient->shouldReceive('stopTraceSpan')->once()->with('test-span')->andReturn(false);
        $mockClient->shouldReceive('isExtensionAvailable')->once()->andReturn(false);
        
        $this->app->instance(PerfbaseClient::class, $mockClient);
        
        $stopResult = Perfbase::stopTraceSpan('test-span');
        $availableResult = Perfbase::isExtensionAvailable();
        
        $this->assertFalse($stopResult);
        $this->assertFalse($availableResult);
    }

    public function testFacadeHandlesExceptions()
    {
        // Mock the Perfbase client to throw an exception
        $mockClient = Mockery::mock(PerfbaseClient::class);
        $mockClient->shouldReceive('startTraceSpan')->once()->andThrow(new \Exception('Test exception'));
        
        $this->app->instance(PerfbaseClient::class, $mockClient);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Test exception');
        
        Perfbase::startTraceSpan('test-span');
    }

    public function testFacadeIsRegisteredInContainer()
    {
        // Test that the facade is properly registered
        $this->assertTrue($this->app->bound(PerfbaseClient::class));
    }

    public function testFacadeUsesServiceProviderBinding()
    {
        // Test that the facade uses the service provider's binding
        $client = $this->app->make(PerfbaseClient::class);
        $facadeClient = Perfbase::getFacadeRoot();
        
        $this->assertSame($client, $facadeClient);
    }

    public function testFacadeMethodsAreStaticallyCalled()
    {
        // Test that facade methods can be called statically
        $this->assertTrue(is_callable([Perfbase::class, 'startTraceSpan']));
        $this->assertTrue(is_callable([Perfbase::class, 'stopTraceSpan']));
        $this->assertTrue(is_callable([Perfbase::class, 'submitTrace']));
        $this->assertTrue(is_callable([Perfbase::class, 'getTraceData']));
        $this->assertTrue(is_callable([Perfbase::class, 'reset']));
        $this->assertTrue(is_callable([Perfbase::class, 'isExtensionAvailable']));
        $this->assertTrue(is_callable([Perfbase::class, 'setAttribute']));
        $this->assertTrue(is_callable([Perfbase::class, 'setFlags']));
    }

    public function testFacadeDocBlocks()
    {
        $reflection = new \ReflectionClass(Perfbase::class);
        $docComment = $reflection->getDocComment();
        
        $this->assertStringContainsString('@method static void startTraceSpan(string $spanName, array<string, string> $attributes = [])', $docComment);
        $this->assertStringContainsString('@method static bool stopTraceSpan(string $spanName)', $docComment);
        $this->assertStringContainsString('@method static void submitTrace()', $docComment);
        $this->assertStringContainsString('@method static string getTraceData(string $spanName = \'\')', $docComment);
        $this->assertStringContainsString('@method static void reset()', $docComment);
        $this->assertStringContainsString('@method static bool isExtensionAvailable()', $docComment);
        $this->assertStringContainsString('@method static void setAttribute(string $key, string $value)', $docComment);
        $this->assertStringContainsString('@method static void setFlags(int $flags)', $docComment);
    }

    public function testFacadeInheritsFromLaravelFacade()
    {
        $this->assertInstanceOf(\Illuminate\Support\Facades\Facade::class, new Perfbase);
    }

    public function testFacadeWithComplexParameters()
    {
        // Mock the Perfbase client
        $mockClient = Mockery::mock(PerfbaseClient::class);
        $mockClient->shouldReceive('setAttribute')->once()->with('complex.key', 'complex value with spaces')->andReturn();
        
        $this->app->instance(PerfbaseClient::class, $mockClient);
        
        Perfbase::setAttribute('complex.key', 'complex value with spaces');
        
        $mockClient->shouldHaveReceived('setAttribute')->once();
        $this->assertTrue(true); // Assert that we got this far
    }

    public function testFacadeWithEmptyArrayParameters()
    {
        // Mock the Perfbase client  
        $mockClient = Mockery::mock(PerfbaseClient::class);
        $mockClient->shouldReceive('startTraceSpan')->once()->with('test-span', [])->andReturn();
        
        $this->app->instance(PerfbaseClient::class, $mockClient);
        
        Perfbase::startTraceSpan('test-span', []);
        
        $mockClient->shouldHaveReceived('startTraceSpan')->once();
        $this->assertTrue(true); // Assert that we got this far
    }

    public function testFacadeWithArrayParameters()
    {
        // Mock the Perfbase client
        $mockClient = Mockery::mock(PerfbaseClient::class);
        $mockClient->shouldReceive('startTraceSpan')->once()->with('test-span', ['key1' => 'value1', 'key2' => 'value2'])->andReturn();
        
        $this->app->instance(PerfbaseClient::class, $mockClient);
        
        Perfbase::startTraceSpan('test-span', ['key1' => 'value1', 'key2' => 'value2']);
        
        $mockClient->shouldHaveReceived('startTraceSpan')->once();
        $this->assertTrue(true); // Assert that we got this far
    }

    public function testFacadeMultipleMethodCalls()
    {
        // Mock the Perfbase client
        $mockClient = Mockery::mock(PerfbaseClient::class);
        $mockClient->shouldReceive('startTraceSpan')->once()->with('test-span')->andReturn();
        $mockClient->shouldReceive('setAttribute')->once()->with('key', 'value')->andReturn();
        $mockClient->shouldReceive('stopTraceSpan')->once()->with('test-span')->andReturn(true);
        
        $this->app->instance(PerfbaseClient::class, $mockClient);
        
        Perfbase::startTraceSpan('test-span');
        Perfbase::setAttribute('key', 'value');
        $result = Perfbase::stopTraceSpan('test-span');
        
        $this->assertTrue($result);
    }

    public function testFacadeWorksInDifferentEnvironments()
    {
        // Test facade works in testing environment
        $this->assertInstanceOf(PerfbaseClient::class, Perfbase::getFacadeRoot());
        
        // Mock for production-like environment
        $mockClient = Mockery::mock(PerfbaseClient::class);
        $mockClient->shouldReceive('isExtensionAvailable')->once()->andReturn(true);
        
        $this->app->instance(PerfbaseClient::class, $mockClient);
        
        // Clear the facade cache to ensure the new mock is used
        Perfbase::clearResolvedInstance(PerfbaseClient::class);
        
        $this->assertTrue(Perfbase::isExtensionAvailable());
    }
}