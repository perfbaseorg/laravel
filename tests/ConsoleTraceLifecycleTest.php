<?php

namespace Tests;

require_once __DIR__ . '/TestHelpers.php';

use Orchestra\Testbench\TestCase;
use Perfbase\Laravel\Lifecycle\ConsoleTraceLifecycle;
use Perfbase\Laravel\PerfbaseServiceProvider;
use Perfbase\Laravel\Support\PerfbaseConfig;
use Perfbase\SDK\Config;
use Perfbase\SDK\Perfbase as PerfbaseClient;
use Perfbase\SDK\SubmitResult;
use Mockery;
use ReflectionClass;

class ConsoleTraceLifecycleTest extends TestCase
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
                'include' => ['console' => ['*']],
                'exclude' => ['console' => []],
            ],
            'app' => ['env' => 'production', 'version' => '3.0.0'],
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testSetsConsoleAttributes(): void
    {
        $lifecycle = new ConsoleTraceLifecycle('migrate');
        $lifecycle->startProfiling();

        $attrs = $this->getAttributes($lifecycle);
        $this->assertSame('console', $attrs['source']);
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
    }

    public function testDoesNotSetHttpAttributes(): void
    {
        $lifecycle = new ConsoleTraceLifecycle('migrate');
        $lifecycle->startProfiling();

        $attrs = $this->getAttributes($lifecycle);
        $this->assertArrayNotHasKey('user_ip', $attrs);
        $this->assertArrayNotHasKey('user_agent', $attrs);
        $this->assertArrayNotHasKey('http_method', $attrs);
        $this->assertArrayNotHasKey('http_url', $attrs);
    }

    public function testShouldProfileReturnsTrueWhenIncluded(): void
    {
        config(['perfbase.include.console' => ['*']]);

        $lifecycle = new ConsoleTraceLifecycle('migrate');
        $this->assertTrue($this->callShouldProfile($lifecycle));
    }

    public function testShouldProfileReturnsFalseWhenNotIncluded(): void
    {
        config(['perfbase.include.console' => ['migrate*']]);

        $lifecycle = new ConsoleTraceLifecycle('queue:work');
        $this->assertFalse($this->callShouldProfile($lifecycle));
    }

    public function testShouldProfileReturnsFalseWhenExcluded(): void
    {
        config([
            'perfbase.include.console' => ['*'],
            'perfbase.exclude.console' => ['queue:work'],
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

        $this->assertSame('console.migrate:fresh', $prop->getValue($lifecycle));
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
