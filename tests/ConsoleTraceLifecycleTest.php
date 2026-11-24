<?php

namespace Tests;

require_once __DIR__ . '/TestHelpers.php';

use Orchestra\Testbench\TestCase;
use Perfbase\Laravel\Lifecycle\ConsoleTraceLifecycle;
use Perfbase\Laravel\Lifecycle\QueueTraceLifecycle;
use Perfbase\Laravel\PerfbaseServiceProvider;
use Perfbase\Laravel\Support\PerfbaseConfig;
use Perfbase\SDK\Config;
use Perfbase\SDK\Perfbase as PerfbaseClient;
use Perfbase\SDK\SubmitResult;
use Mockery;
use Perfbase\SDK\Extension\ExtensionInterface;
use Perfbase\SDK\Http\ApiClient;
use Perfbase\SDK\Http\HttpClientInterface;use ReflectionClass;

class ConsoleTraceLifecycleTest extends TestCase
{
    private $perfbaseClient;
    private ?string $originalServerAddr = null;

    protected function getPackageProviders($app): array
    {
        return [PerfbaseServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        PerfbaseConfig::clearCache();
        $this->originalServerAddr = $_SERVER['SERVER_ADDR'] ?? null;
        $_SERVER['SERVER_ADDR'] = '10.11.12.13';

        $this->perfbaseClient = Mockery::mock(PerfbaseClient::class);
        $this->perfbaseClient->allows('isExtensionAvailable')->andReturns(true);
        $this->perfbaseClient->allows('startTraceSpan');
        $this->perfbaseClient->allows('stopTraceSpan')->andReturns(true);
        $this->perfbaseClient->allows('setAttribute');
        $this->perfbaseClient->allows('submitTrace')->andReturns(SubmitResult::success());
        $this->perfbaseClient->allows('reset');

        $this->app->instance(Config::class, Mockery::mock(Config::class));
        $this->app->instance(PerfbaseClient::class, $this->perfbaseClient);

        config([
            'perfbase' => [
                'enabled' => true,
                'sample_rate' => 1.0,
                'include' => ['artisan' => ['*']],
                'exclude' => ['artisan' => []],
            ],
            'app' => ['env' => 'production', 'version' => '3.0.0'],
        ]);
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

    public function testSetsConsoleAttributes(): void
    {
        $lifecycle = new ConsoleTraceLifecycle('migrate');
        $lifecycle->startProfiling();

        $attrs = $this->getAttributes($lifecycle);
        $this->assertSame('artisan', $attrs['source']);
        $this->assertSame('migrate', $attrs['action']);
    }

    public function testSetsBaseAttributes(): void
    {
        $lifecycle = new ConsoleTraceLifecycle('db:seed');
        $lifecycle->startProfiling();

        $attrs = $this->getAttributes($lifecycle);
        $this->assertArrayHasKey('hostname', $attrs);
        $this->assertSame('production', $attrs['environment']);
        $this->assertSame('3.0.0', $attrs['app_version']);
        $this->assertArrayHasKey('php_version', $attrs);
        $this->assertSame('10.11.12.13', $attrs['user_ip']);
    }

    public function testDoesNotSetHttpAttributes(): void
    {
        $lifecycle = new ConsoleTraceLifecycle('migrate');
        $lifecycle->startProfiling();

        $attrs = $this->getAttributes($lifecycle);
        $this->assertArrayNotHasKey('user_agent', $attrs);
        $this->assertArrayNotHasKey('http_method', $attrs);
        $this->assertArrayNotHasKey('http_url', $attrs);
    }

    public function testShouldProfileReturnsTrueWhenIncluded(): void
    {
        config(['perfbase.include.artisan' => ['*']]);

        $lifecycle = new ConsoleTraceLifecycle('migrate');
        $this->assertTrue($this->callShouldProfile($lifecycle));
    }

    public function testShouldProfileReturnsFalseWhenNotIncluded(): void
    {
        config(['perfbase.include.artisan' => ['migrate*']]);

        $lifecycle = new ConsoleTraceLifecycle('queue:work');
        $this->assertFalse($this->callShouldProfile($lifecycle));
    }

    public function testShouldProfileReturnsFalseWhenExcluded(): void
    {
        config([
            'perfbase.include.artisan' => ['*'],
            'perfbase.exclude.artisan' => ['queue:work'],
        ]);

        $lifecycle = new ConsoleTraceLifecycle('queue:work');
        $this->assertFalse($this->callShouldProfile($lifecycle));
    }

    public function testSpanName(): void
    {
        $lifecycle = new ConsoleTraceLifecycle('migrate:fresh');

        $reflection = new ReflectionClass($lifecycle);
        $prop = $reflection->getProperty('spanName');
        $prop->setAccessible(true);

        $this->assertSame('artisan', $prop->getValue($lifecycle));
    }

    public function testSetExitCode(): void
    {
        $lifecycle = new ConsoleTraceLifecycle('migrate');
        $lifecycle->setExitCode(1);

        $attrs = $this->getAttributes($lifecycle);
        $this->assertSame('1', $attrs['exit_code']);
    }

    public function testSetExitCodeZero(): void
    {
        $lifecycle = new ConsoleTraceLifecycle('migrate');
        $lifecycle->setExitCode(0);

        $attrs = $this->getAttributes($lifecycle);
        $this->assertSame('0', $attrs['exit_code']);
    }

    public function testStartProfilingUsesArtisanSpanName(): void
    {
        $client = Mockery::mock(PerfbaseClient::class);
        $client->allows('isExtensionAvailable')->andReturns(true);
        $client->shouldReceive('startTraceSpan')->once()->with('artisan');
        $client->allows('stopTraceSpan')->andReturns(true);
        $client->allows('setAttribute');
        $client->allows('submitTrace')->andReturns(SubmitResult::success());
        $client->allows('reset');
        $this->app->instance(PerfbaseClient::class, $client);

        $lifecycle = new ConsoleTraceLifecycle('migrate:fresh');
        $lifecycle->startProfiling();
        $this->addToAssertionCount(1);
    }

    public function testNestedCommandsProfileOnlyTheOutermostCommand(): void
    {
        // A command that runs Artisan::call() inside itself starts a second
        // console lifecycle while the first is still profiling. Both share
        // the "artisan" span name, so only the outermost one may own it.
        $extension = Mockery::mock(ExtensionInterface::class);
        $extension->allows('isAvailable')->andReturns(true);
        $extension->allows('getFlags')->andReturns(0);
        $extension->allows('getSpanData')->andReturns('trace-data');
        $extension->allows('getWireVersion')->andReturns(1);
        $extension->allows('getVersion')->andReturns('1.0.0');
        $extension->allows('reset');
        // The one submitted trace must describe the outer command, not the
        // nested one that happened to finish first.
        $extension->shouldReceive('setAttribute')->once()->with('action', 'migrate:fresh');
        $extension->shouldReceive('setAttribute')->byDefault();
        $extension->shouldReceive('startSpan')->once()->with('artisan', Mockery::any(), Mockery::any());
        $extension->shouldReceive('stopSpan')->once()->with('artisan');

        $httpClient = Mockery::mock(HttpClientInterface::class);
        $httpClient->allows('post')->andReturns(SubmitResult::success());

        $sdkConfig = Config::new('test-key', 0);
        $this->app->instance(
            PerfbaseClient::class,
            new PerfbaseClient($sdkConfig, $extension, new ApiClient($sdkConfig, $httpClient))
        );

        // Called directly rather than through the console events: the event
        // listeners swallow errors, and the real SDK raises a warning when a
        // nested lifecycle starts the "artisan" span a second time.
        $outer = new ConsoleTraceLifecycle('migrate:fresh');
        $inner = new ConsoleTraceLifecycle('migrate');

        $outer->startProfiling();
        $inner->startProfiling();
        $inner->stopProfiling();
        $outer->stopProfiling();

        $this->addToAssertionCount(1);
    }

    public function testNestedJobsProfileOnlyTheOutermostJob(): void
    {
        // A job dispatched on the sync connection runs inside the job that
        // dispatched it; both share the "queue" span name.
        $extension = Mockery::mock(ExtensionInterface::class);
        $extension->allows('isAvailable')->andReturns(true);
        $extension->allows('getFlags')->andReturns(0);
        $extension->allows('getSpanData')->andReturns('trace-data');
        $extension->allows('getWireVersion')->andReturns(1);
        $extension->allows('getVersion')->andReturns('1.0.0');
        $extension->allows('reset');
        $extension->shouldReceive('setAttribute')->once()->with('action', 'App\\Jobs\\OuterJob');
        $extension->shouldReceive('setAttribute')->byDefault();
        $extension->shouldReceive('startSpan')->once()->with('queue', Mockery::any(), Mockery::any());
        $extension->shouldReceive('stopSpan')->once()->with('queue');

        $httpClient = Mockery::mock(HttpClientInterface::class);
        $httpClient->allows('post')->andReturns(SubmitResult::success());

        $sdkConfig = Config::new('test-key', 0);
        $client = new PerfbaseClient($sdkConfig, $extension, new ApiClient($sdkConfig, $httpClient));
        $this->app->instance(PerfbaseClient::class, $client);
        config(['perfbase.include.jobs' => ['*']]);

        $outer = new QueueTraceLifecycle('App\\Jobs\\OuterJob', 'default', 'sync');
        $inner = new QueueTraceLifecycle('App\\Jobs\\InnerJob', 'default', 'sync');

        $outer->startProfiling();
        $inner->startProfiling();
        $inner->stopProfiling();
        $outer->stopProfiling();

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, string>
     */
    private function getAttributes(ConsoleTraceLifecycle $lifecycle): array
    {
        $reflection = new ReflectionClass($lifecycle);
        $prop = $reflection->getProperty('attributes');
        $prop->setAccessible(true);
        return $prop->getValue($lifecycle);
    }

    private function callShouldProfile(ConsoleTraceLifecycle $lifecycle): bool
    {
        $reflection = new ReflectionClass($lifecycle);
        $method = $reflection->getMethod('shouldProfile');
        $method->setAccessible(true);
        return $method->invoke($lifecycle);
    }
}
