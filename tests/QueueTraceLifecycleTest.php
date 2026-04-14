<?php

namespace Tests;

require_once __DIR__ . '/TestHelpers.php';

use Orchestra\Testbench\TestCase;
use Perfbase\Laravel\Lifecycle\QueueTraceLifecycle;
use Perfbase\Laravel\PerfbaseServiceProvider;
use Perfbase\Laravel\Support\PerfbaseConfig;
use Perfbase\SDK\Config;
use Perfbase\SDK\Perfbase as PerfbaseClient;
use Perfbase\SDK\SubmitResult;
use Mockery;
use ReflectionClass;

class QueueTraceLifecycleTest extends TestCase
{
    private $perfbaseClient;

    protected function getPackageProviders($app): array
    {
        return [PerfbaseServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        PerfbaseConfig::clearCache();

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
                'include' => ['queue' => ['*']],
                'exclude' => ['queue' => []],
            ],
            'app' => ['env' => 'testing', 'version' => '2.0.0'],
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testSetsQueueAttributes(): void
    {
        $lifecycle = new QueueTraceLifecycle('App\Jobs\SendEmail', 'default', 'redis');
        $lifecycle->startProfiling();

        $attrs = $this->getAttributes($lifecycle);
        $this->assertSame('queue', $attrs['source']);
        $this->assertSame('App\Jobs\SendEmail', $attrs['action']);
        $this->assertSame('default', $attrs['queue']);
        $this->assertSame('redis', $attrs['connection']);
    }

    public function testSetsBaseAttributes(): void
    {
        $lifecycle = new QueueTraceLifecycle('App\Jobs\Test', 'high', 'database');
        $lifecycle->startProfiling();

        $attrs = $this->getAttributes($lifecycle);
        $this->assertArrayHasKey('hostname', $attrs);
        $this->assertSame('testing', $attrs['environment']);
        $this->assertSame('2.0.0', $attrs['app_version']);
        $this->assertArrayHasKey('php_version', $attrs);
    }

    public function testDoesNotSetHttpAttributes(): void
    {
        $lifecycle = new QueueTraceLifecycle('App\Jobs\Test', 'default', 'redis');
        $lifecycle->startProfiling();

        $attrs = $this->getAttributes($lifecycle);
        $this->assertArrayNotHasKey('user_ip', $attrs);
        $this->assertArrayNotHasKey('user_agent', $attrs);
        $this->assertArrayNotHasKey('http_method', $attrs);
        $this->assertArrayNotHasKey('http_url', $attrs);
    }

    public function testShouldProfileReturnsTrueWhenIncluded(): void
    {
        config(['perfbase.include.queue' => ['*']]);

        $lifecycle = new QueueTraceLifecycle('App\Jobs\AnyJob', 'default', 'redis');
        $this->assertTrue($this->callShouldProfile($lifecycle));
    }

    public function testShouldProfileReturnsFalseWhenNotIncluded(): void
    {
        config(['perfbase.include.queue' => ['App\Jobs\Important*']]);

        $lifecycle = new QueueTraceLifecycle('App\Jobs\TrivialCleanup', 'default', 'redis');
        $this->assertFalse($this->callShouldProfile($lifecycle));
    }

    public function testShouldProfileReturnsFalseWhenExcluded(): void
    {
        config([
            'perfbase.include.queue' => ['*'],
            'perfbase.exclude.queue' => ['App\Jobs\Noisy*'],
        ]);

        $lifecycle = new QueueTraceLifecycle('App\Jobs\NoisyHeartbeat', 'default', 'redis');
        $this->assertFalse($this->callShouldProfile($lifecycle));
    }

    public function testSpanNameUsesClassBasename(): void
    {
        $lifecycle = new QueueTraceLifecycle('App\Jobs\Nested\DeepJob', 'default', 'redis');

        $reflection = new ReflectionClass($lifecycle);
        $prop = $reflection->getProperty('spanName');
        $prop->setAccessible(true);

        $this->assertSame('queue', $prop->getValue($lifecycle));
    }

    public function testSetExceptionAttribute(): void
    {
        $lifecycle = new QueueTraceLifecycle('App\Jobs\Failing', 'default', 'redis');
        $lifecycle->setException('Something went wrong');

        $attrs = $this->getAttributes($lifecycle);
        $this->assertSame('Something went wrong', $attrs['exception']);
    }

    public function testStartProfilingUsesQueueSpanName(): void
    {
        $client = Mockery::mock(PerfbaseClient::class);
        $client->allows('isExtensionAvailable')->andReturns(true);
        $client->shouldReceive('startTraceSpan')->once()->with('queue');
        $client->allows('stopTraceSpan')->andReturns(true);
        $client->allows('setAttribute');
        $client->allows('submitTrace')->andReturns(SubmitResult::success());
        $client->allows('reset');
        $this->app->instance(PerfbaseClient::class, $client);

        $lifecycle = new QueueTraceLifecycle('App\Jobs\Nested\DeepJob', 'default', 'redis');
        $lifecycle->startProfiling();
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, string>
     */
    private function getAttributes(QueueTraceLifecycle $lifecycle): array
    {
        $reflection = new ReflectionClass($lifecycle);
        $prop = $reflection->getProperty('attributes');
        $prop->setAccessible(true);
        return $prop->getValue($lifecycle);
    }

    private function callShouldProfile(QueueTraceLifecycle $lifecycle): bool
    {
        $reflection = new ReflectionClass($lifecycle);
        $method = $reflection->getMethod('shouldProfile');
        $method->setAccessible(true);
        return $method->invoke($lifecycle);
    }
}
